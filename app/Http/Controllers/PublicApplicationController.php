<?php

namespace App\Http\Controllers;

use App\Models\{AlertaSaas,Cliente,Conversacion,Cuestionario,Empresa,PlanViti,SolicitudRespuesta,SolicitudSistema,Usuario};
use App\Support\{Audit,Code,FirebasePush};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicApplicationController extends Controller
{
    private const PAYMENT_OPTIONS = ['contado','50_50','tres_partes','por_definir'];
    private const OPERATION_QUESTION_NUMBERS = [12,13,17,18,19,27,32,35,70];

    public function catalog(): JsonResponse
    {
        $questionnaire = Cuestionario::query()
            ->where('activo', true)
            ->with(['secciones.preguntas'])
            ->latest('id')
            ->first();

        abort_unless($questionnaire, 422, 'VITI no tiene un cuestionario activo en este momento.');

        $plans = PlanViti::query()
            ->where('activo', true)
            ->orderBy('id')
            ->get([
                'id','codigo','nombre','descripcion','precio_proyecto','precio_mensual','precio_anual',
                'dias_prueba','modulos','max_usuarios','max_aplicaciones',
            ]);

        return response()->json([
            'data' => [
                'planes' => $plans->values(),
                'cuestionario' => [
                    'id' => $questionnaire->id,
                    'titulo' => $questionnaire->titulo,
                    'secciones' => $questionnaire->secciones->map(fn ($section) => [
                        'id' => $section->id,
                        'titulo' => $section->titulo,
                        'descripcion' => $section->descripcion,
                        'orden' => $section->orden,
                        'preguntas' => $section->preguntas->map(fn ($question) => [
                            'id' => $question->id,
                            'numero' => $question->numero,
                            'enunciado' => $question->enunciado,
                            'tipo' => $question->tipo,
                            'obligatoria' => (bool) $question->obligatoria,
                            'opciones' => $question->opciones,
                            'ayuda' => $question->ayuda,
                            'orden' => $question->orden,
                        ])->values(),
                    ])->values(),
                ],
            ],
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $request->merge([
            'correo' => Str::lower(trim((string) $request->input('correo'))),
        ]);

        $data = $request->validate([
            'nombre' => ['required','string','min:3','max:180'],
            'correo' => ['required','email','max:160'],
            'telefono' => ['required','regex:/^[0-9]{7,15}$/'],
            'whatsapp' => ['nullable','regex:/^[0-9]{7,15}$/'],
            'documento' => ['nullable','string','max:50'],
            'ci_expedido' => ['nullable','string','max:20'],
            'ciudad' => ['required','string','max:100'],
            'direccion' => ['nullable','string','max:255'],
            'empresa_nombre' => ['required','string','max:180'],
            'empresa_actividad' => ['nullable','string','max:200'],
            'empresa_telefono' => ['nullable','string','max:30'],
            'empresa_whatsapp' => ['nullable','string','max:30'],
            'empresa_ciudad' => ['nullable','string','max:100'],
            'empresa_direccion' => ['nullable','string','max:255'],
            'titulo_sistema' => ['required','string','max:200'],
            'resumen' => ['nullable','string','max:5000'],
            'plan_codigo' => ['required','string','max:80'],
            'forma_pago_preferida' => ['required', Rule::in(self::PAYMENT_OPTIONS)],
            'frecuencia_suscripcion_preferida' => ['nullable', Rule::in(['mensual','anual'])],
            'declaracion_aceptada' => ['required','accepted'],
            'declaracion_nombre' => ['required','string','min:3','max:180'],
            'declaracion_fecha' => ['required','date'],
            'acuerdo_comercial_aceptado' => ['required','accepted'],
            'acuerdo_comercial_nombre' => ['required','string','min:3','max:180'],
            'acuerdo_comercial_fecha' => ['required','date'],
            'respuestas' => ['required','array'],
            'respuestas.*.pregunta_id' => ['required','integer'],
            'respuestas.*.valor' => ['nullable'],
        ]);

        $questionnaire = Cuestionario::query()
            ->where('activo', true)
            ->with(['secciones.preguntas'])
            ->latest('id')
            ->firstOrFail();

        $plan = PlanViti::query()
            ->where('activo', true)
            ->where('codigo', trim((string) $data['plan_codigo']))
            ->first();
        abort_unless($plan, 422, 'El plan seleccionado ya no está disponible.');

        if ($this->planKey($plan) !== 'custom' && $plan->precio_mensual !== null && $plan->precio_anual !== null) {
            abort_unless(filled($data['frecuencia_suscripcion_preferida']), 422, 'Selecciona mensual o anual para continuar.');
        }

        abort_unless(
            in_array($data['forma_pago_preferida'], $this->allowedPayments($plan), true),
            422,
            'La forma de pago seleccionada no corresponde al plan elegido.'
        );

        $questions = $questionnaire->secciones->flatMap(fn ($section) => $section->preguntas)->keyBy('id');
        $allowedNumbers = $this->allowedOperationNumbers($plan);
        $incoming = collect($data['respuestas'])->keyBy(fn ($item) => (int) $item['pregunta_id']);

        foreach ($questions as $question) {
            if (!$question->obligatoria) continue;
            $operation = in_array((int) $question->numero, self::OPERATION_QUESTION_NUMBERS, true);
            if ($operation && !in_array((int) $question->numero, $allowedNumbers, true)) continue;
            $item = $incoming->get((int) $question->id);
            $value = $item['valor'] ?? null;
            $filled = is_array($value) ? count($value) > 0 : strlen(trim((string) $value)) > 0;
            abort_unless($filled, 422, "Completa la pregunta {$question->numero} antes de enviar la solicitud.");
        }

        [$solicitud, $cliente, $empresa] = DB::transaction(function () use ($data, $questionnaire, $plan, $questions, $incoming, $allowedNumbers, $request): array {
            $documento = filled($data['documento'] ?? null) ? trim($data['documento']) : null;
            $byPhone = Cliente::query()->where('telefono', $data['telefono'])->first();
            $byEmail = Cliente::query()->where('correo', $data['correo'])->first();

            abort_if($byPhone && $byEmail && (int) $byPhone->id !== (int) $byEmail->id, 422, 'El teléfono y el correo pertenecen a registros diferentes.');
            $cliente = $byPhone ?: $byEmail;
            abort_if($cliente && $cliente->usuario()->exists(), 422, 'Este responsable ya tiene una cuenta VITI. Inicia sesión para administrar tus solicitudes.');

            if ($cliente) {
                if ($documento) {
                    abort_if(Cliente::query()->where('documento', $documento)->whereKeyNot($cliente->id)->exists(), 422, 'Ese documento ya pertenece a otro cliente.');
                    abort_if(filled($cliente->documento) && $cliente->documento !== $documento, 422, 'El documento indicado no coincide con el registro existente.');
                }
                $cliente->update([
                    'nombre' => trim($data['nombre']),
                    'telefono' => $data['telefono'],
                    'correo' => $data['correo'],
                    'whatsapp' => $data['whatsapp'] ?? $cliente->whatsapp ?? $data['telefono'],
                    'documento' => $cliente->documento ?: $documento,
                    'ci_expedido' => $cliente->ci_expedido ?: ($data['ci_expedido'] ?? null),
                    'ciudad' => trim($data['ciudad']),
                    'direccion' => $data['direccion'] ?? $cliente->direccion,
                    'estado' => 'informacion_recibida',
                ]);
            } else {
                abort_if($documento && Cliente::query()->where('documento', $documento)->exists(), 422, 'Ese documento ya está registrado con otro cliente.');
                $cliente = Cliente::create([
                    'nombre' => trim($data['nombre']),
                    'telefono' => $data['telefono'],
                    'correo' => $data['correo'],
                    'whatsapp' => $data['whatsapp'] ?? $data['telefono'],
                    'documento' => $documento,
                    'ci_expedido' => $data['ci_expedido'] ?? null,
                    'ciudad' => trim($data['ciudad']),
                    'direccion' => $data['direccion'] ?? null,
                    'estado' => 'informacion_recibida',
                    'canal_origen' => 'viti_web_unificado',
                ]);
            }

            $empresa = Empresa::query()
                ->where('cliente_id', $cliente->id)
                ->whereRaw('LOWER(nombre_comercial) = ?', [Str::lower(trim($data['empresa_nombre']))])
                ->first();

            $companyData = [
                'actividad' => $data['empresa_actividad'] ?? null,
                'telefono' => $data['empresa_telefono'] ?? $data['telefono'],
                'whatsapp' => $data['empresa_whatsapp'] ?? ($data['whatsapp'] ?? $data['telefono']),
                'ciudad' => $data['empresa_ciudad'] ?? $data['ciudad'],
                'direccion' => $data['empresa_direccion'] ?? ($data['direccion'] ?? null),
                'estado' => 'pendiente_revision',
            ];

            if ($empresa) {
                $empresa->update($companyData);
            } else {
                $empresa = Empresa::create($companyData + [
                    'cliente_id' => $cliente->id,
                    'codigo' => Code::next('empresas','EMP'),
                    'nombre_comercial' => trim($data['empresa_nombre']),
                ]);
            }

            $solicitud = SolicitudSistema::create([
                'empresa_id' => $empresa->id,
                'cliente_id' => $cliente->id,
                'cuestionario_id' => $questionnaire->id,
                'codigo' => Code::next('solicitudes_sistema','SOL'),
                'public_token' => Str::random(48),
                'publico_habilitado' => true,
                'titulo' => trim($data['titulo_sistema']),
                'resumen' => $data['resumen'] ?? null,
                'estado' => 'en_revision',
                'prioridad' => 'normal',
                'plan_viti_id' => $plan->id,
                'presupuesto_estimado' => $plan->precio_proyecto,
                'forma_pago_preferida' => $data['forma_pago_preferida'],
                'frecuencia_suscripcion_preferida' => $this->planKey($plan) === 'custom' ? null : ($data['frecuencia_suscripcion_preferida'] ?? null),
                'acuerdo_comercial_requerido' => true,
                'acuerdo_comercial_aceptado' => true,
                'acuerdo_comercial_nombre' => $data['acuerdo_comercial_nombre'],
                'acuerdo_comercial_fecha' => $data['acuerdo_comercial_fecha'],
                'declaracion_aceptada' => true,
                'declaracion_nombre' => $data['declaracion_nombre'],
                'declaracion_fecha' => $data['declaracion_fecha'],
                'enviado_at' => now(),
            ]);

            foreach ($incoming as $item) {
                $question = $questions->get((int) $item['pregunta_id']);
                abort_unless($question, 422, 'Una respuesta no pertenece al cuestionario de VITI.');
                if (in_array((int) $question->numero, self::OPERATION_QUESTION_NUMBERS, true)
                    && !in_array((int) $question->numero, $allowedNumbers, true)) {
                    continue;
                }
                $value = $item['valor'] ?? null;
                SolicitudRespuesta::create([
                    'solicitud_id' => $solicitud->id,
                    'pregunta_id' => $question->id,
                    'respuesta_texto' => is_array($value) ? null : ($value === null ? null : (string) $value),
                    'respuesta_json' => is_array($value) ? $value : null,
                    'origen' => 'cliente',
                ]);
            }

            Conversacion::firstOrCreate(
                ['cliente_id' => $cliente->id, 'solicitud_id' => $solicitud->id],
                ['asunto' => 'Revisión de '.$solicitud->codigo, 'estado' => 'abierta', 'ultimo_mensaje_at' => now()]
            );

            return [$solicitud, $cliente, $empresa];
        });

        Audit::log($request, 'solicitud_viti_unificada_enviada', $solicitud, 'El cliente completó el flujo público de VITI y envió la solicitud para evaluación.', [
            'plan' => $plan->codigo,
            'cliente_id' => $cliente->id,
            'empresa_id' => $empresa->id,
        ]);

        $admins = Usuario::query()
            ->where('estado', 'activo')
            ->whereIn('rol', ['superadmin','administrador'])
            ->get(['id']);

        $message = $solicitud->codigo.' · '.$empresa->nombre_comercial.' · '.$cliente->nombre;
        foreach ($admins as $admin) {
            AlertaSaas::firstOrCreate(
                ['usuario_id'=>$admin->id,'clave'=>'solicitud_unificada_'.$solicitud->id],
                [
                    'empresa_id'=>$empresa->id,
                    'tipo'=>'solicitud',
                    'titulo'=>'Nueva solicitud VITI en revisión',
                    'mensaje'=>$message,
                    'ruta'=>'/solicitudes/'.$solicitud->id,
                ]
            );
        }
        FirebasePush::sendToUsers($admins->pluck('id')->all(), 'Nueva solicitud VITI', $message, [
            'type' => 'solicitud',
            'solicitud_id' => $solicitud->id,
            'path' => '/solicitudes/'.$solicitud->id,
        ]);

        return response()->json([
            'message' => 'Solicitud recibida. AGR Studio revisará tu información antes de habilitar cualquier acceso.',
            'data' => [
                'codigo' => $solicitud->codigo,
                'estado' => 'en_revision',
                'plan' => ['codigo'=>$plan->codigo,'nombre'=>$plan->nombre],
                'correo' => $cliente->correo,
            ],
        ], 201);
    }

    private function allowedPayments(?PlanViti $plan): array
    {
        return match ($this->planKey($plan)) {
            'custom' => ['por_definir'],
            'professional', 'enterprise' => ['tres_partes','contado','por_definir'],
            default => ['50_50','contado','por_definir'],
        };
    }

    private function allowedOperationNumbers(?PlanViti $plan): array
    {
        $numbers = [12,13,17,18,19];
        return match ($this->planKey($plan)) {
            'professional' => [...$numbers,32,35],
            'enterprise' => [...$numbers,27,32,35],
            'custom' => [...$numbers,70],
            default => $numbers,
        };
    }

    private function planKey(?PlanViti $plan): string
    {
        $code = Str::lower((string) ($plan?->codigo ?? ''));
        if (Str::contains($code, 'personalizado')) return 'custom';
        if (Str::contains($code, 'empresa')) return 'enterprise';
        if (Str::contains($code, 'profesional')) return 'professional';
        return 'initial';
    }
}
