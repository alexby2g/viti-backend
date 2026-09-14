<?php

namespace App\Http\Controllers;

use App\Models\{AlertaSaas,Cliente,Conversacion,Cuestionario,Empresa,PlanViti,SolicitudRespuesta,SolicitudSistema,Usuario};
use App\Support\{Audit,Code,FirebasePush};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PublicApplicationController extends Controller
{
    private const PAYMENT_OPTIONS = ['contado','50_50','tres_partes','por_definir'];

    public function catalog(): JsonResponse
    {
        $questionnaire = Cuestionario::query()->where('activo', true)->latest('id')->first();
        abort_unless($questionnaire, 422, 'VITI no tiene la configuración de solicitudes activa en este momento.');

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
                // Se conserva la referencia para trazabilidad interna, pero el cuestionario técnico
                // ya no se envía al visitante. El levantamiento detallado ocurre después.
                'cuestionario' => [
                    'id' => $questionnaire->id,
                    'titulo' => $questionnaire->titulo,
                    'secciones' => [],
                ],
            ],
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $today = now()->toDateString();
        $accepted = $request->boolean('terminos_aceptados')
            || $request->boolean('declaracion_aceptada')
            || $request->boolean('acuerdo_comercial_aceptado');
        $name = trim((string) $request->input('nombre'));

        // Compatibilidad con clientes anteriores: el nuevo flujo solo pide una confirmación.
        $request->merge([
            'correo' => Str::lower(trim((string) $request->input('correo'))),
            'forma_pago_preferida' => $request->input('forma_pago_preferida') ?: 'por_definir',
            'declaracion_aceptada' => $accepted,
            'declaracion_nombre' => $request->input('declaracion_nombre') ?: $name,
            'declaracion_fecha' => $request->input('declaracion_fecha') ?: $today,
            'acuerdo_comercial_aceptado' => $accepted,
            'acuerdo_comercial_nombre' => $request->input('acuerdo_comercial_nombre') ?: $name,
            'acuerdo_comercial_fecha' => $request->input('acuerdo_comercial_fecha') ?: $today,
            'respuestas' => is_array($request->input('respuestas')) ? $request->input('respuestas') : [],
        ]);

        $data = $request->validate([
            'nombre' => ['required','string','min:3','max:180'],
            'correo' => ['required','email','max:160'],
            'telefono' => ['required','regex:/^[0-9]{7,15}$/'],
            'whatsapp' => ['nullable','regex:/^[0-9]{7,15}$/'],
            'whatsapp_business' => ['nullable','regex:/^[0-9]{7,15}$/'],
            'documento' => ['nullable','string','max:50'],
            'ci_expedido' => ['nullable','string','max:20'],
            'ciudad' => ['nullable','string','max:100'],
            'direccion' => ['nullable','string','max:255'],
            'empresa_nombre' => ['required','string','max:180'],
            'empresa_actividad' => ['nullable','string','max:200'],
            'empresa_telefono' => ['nullable','string','max:30'],
            'empresa_whatsapp' => ['nullable','string','max:30'],
            'empresa_ciudad' => ['nullable','string','max:100'],
            'empresa_direccion' => ['nullable','string','max:255'],
            'titulo_sistema' => ['required','string','min:3','max:200'],
            'resumen' => ['required','string','min:8','max:5000'],
            'plan_codigo' => ['required','string','max:80'],
            'forma_pago_preferida' => ['required', Rule::in(self::PAYMENT_OPTIONS)],
            'frecuencia_suscripcion_preferida' => ['nullable', Rule::in(['mensual','anual'])],
            'declaracion_aceptada' => ['required','accepted'],
            'declaracion_nombre' => ['required','string','min:3','max:180'],
            'declaracion_fecha' => ['required','date'],
            'acuerdo_comercial_aceptado' => ['required','accepted'],
            'acuerdo_comercial_nombre' => ['required','string','min:3','max:180'],
            'acuerdo_comercial_fecha' => ['required','date'],
            'respuestas' => ['nullable','array'],
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

        $questions = $questionnaire->secciones->flatMap(fn ($section) => $section->preguntas);
        $questionsById = $questions->keyBy('id');
        $questionsByNumber = $questions->keyBy(fn ($question) => (int) $question->numero);
        $incoming = collect($data['respuestas'] ?? [])->keyBy(fn ($item) => (int) $item['pregunta_id']);

        [$solicitud, $cliente, $empresa] = DB::transaction(function () use ($data, $questionnaire, $plan, $questionsById, $questionsByNumber, $incoming, $request): array {
            $documento = filled($data['documento'] ?? null) ? trim($data['documento']) : null;
            $byPhone = Cliente::query()->where('telefono', $data['telefono'])->first();
            $byEmail = Cliente::query()->where('correo', $data['correo'])->first();

            abort_if($byPhone && $byEmail && (int) $byPhone->id !== (int) $byEmail->id, 422, 'El teléfono y el correo pertenecen a registros diferentes.');
            $cliente = $byPhone ?: $byEmail;

            if ($cliente) {
                if ($documento) {
                    abort_if(Cliente::query()->where('documento', $documento)->whereKeyNot($cliente->id)->exists(), 422, 'Ese documento ya pertenece a otro cliente.');
                }
                $cliente->update([
                    'nombre' => trim($data['nombre']),
                    'telefono' => $data['telefono'],
                    'correo' => $data['correo'],
                    'whatsapp' => $data['whatsapp'] ?? $cliente->whatsapp ?? $data['telefono'],
                    'whatsapp_business' => $data['whatsapp_business'] ?? $cliente->whatsapp_business,
                    'documento' => $cliente->documento ?: $documento,
                    'ci_expedido' => $cliente->ci_expedido ?: ($data['ci_expedido'] ?? null),
                    'ciudad' => filled($data['ciudad'] ?? null) ? trim((string) $data['ciudad']) : $cliente->ciudad,
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
                    'whatsapp_business' => $data['whatsapp_business'] ?? null,
                    'documento' => $documento,
                    'ci_expedido' => $data['ci_expedido'] ?? null,
                    'ciudad' => filled($data['ciudad'] ?? null) ? trim((string) $data['ciudad']) : null,
                    'direccion' => $data['direccion'] ?? null,
                    'estado' => 'informacion_recibida',
                    'canal_origen' => 'viti_web_simple',
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
                'ciudad' => $data['empresa_ciudad'] ?? ($data['ciudad'] ?? null),
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

            // Si el visitante ya creó su cuenta antes de solicitar, conservamos el mismo
            // responsable y vinculamos el nuevo negocio a su usuario automáticamente.
            $existingUser = $cliente->usuario()->first();
            if ($existingUser) {
                $empresa->usuarios()->syncWithoutDetaching([
                    $existingUser->id => ['rol_negocio'=>'propietario','activo'=>true],
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
                'resumen' => trim($data['resumen']),
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

            // El formulario corto alimenta automáticamente las preguntas internas esenciales.
            // El resto se completa durante el levantamiento en VITI, no en la puerta de entrada.
            $derived = [
                1 => trim($data['empresa_nombre']),
                2 => trim($data['nombre']),
                3 => $data['telefono'],
                4 => trim((string) ($data['empresa_actividad'] ?? 'Por definir durante el levantamiento')),
                7 => trim($data['titulo_sistema']),
                9 => trim($data['resumen']),
                18 => trim($data['titulo_sistema']),
                19 => trim($data['resumen']),
            ];

            foreach ($derived as $number => $value) {
                $question = $questionsByNumber->get($number);
                if (!$question || !filled($value)) continue;
                SolicitudRespuesta::updateOrCreate(
                    ['solicitud_id' => $solicitud->id, 'pregunta_id' => $question->id],
                    ['respuesta_texto' => (string) $value, 'respuesta_json' => null, 'origen' => 'sistema']
                );
            }

            // Conserva respuestas explícitas enviadas por integraciones antiguas sin obligar al visitante a llenarlas.
            foreach ($incoming as $item) {
                $question = $questionsById->get((int) $item['pregunta_id']);
                if (!$question) continue;
                $value = $item['valor'] ?? null;
                $hasValue = is_array($value) ? count($value) > 0 : filled($value);
                if (!$hasValue) continue;
                SolicitudRespuesta::updateOrCreate(
                    ['solicitud_id' => $solicitud->id, 'pregunta_id' => $question->id],
                    [
                        'respuesta_texto' => is_array($value) ? null : (string) $value,
                        'respuesta_json' => is_array($value) ? $value : null,
                        'origen' => 'cliente',
                    ]
                );
            }

            Conversacion::firstOrCreate(
                ['cliente_id' => $cliente->id, 'solicitud_id' => $solicitud->id],
                ['asunto' => 'Revisión de '.$solicitud->codigo, 'estado' => 'abierta', 'ultimo_mensaje_at' => now()]
            );

            return [$solicitud, $cliente, $empresa];
        });

        Audit::log($request, 'solicitud_viti_simple_enviada', $solicitud, 'Se recibió una solicitud pública breve para evaluación.', [
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
                ['usuario_id'=>$admin->id,'clave'=>'solicitud_simple_'.$solicitud->id],
                [
                    'empresa_id'=>$empresa->id,
                    'tipo'=>'solicitud',
                    'titulo'=>'Nueva solicitud para revisar',
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
            'message' => 'Solicitud recibida. VITI revisará tu necesidad y te mostrará el siguiente paso antes de iniciar el desarrollo.',
            'data' => [
                'codigo' => $solicitud->codigo,
                'estado' => 'en_revision',
                'plan' => ['codigo'=>$plan->codigo,'nombre'=>$plan->nombre],
                'correo' => $cliente->correo,
                'cuenta_existente' => $cliente->usuario()->exists(),
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

    private function planKey(?PlanViti $plan): string
    {
        $code = Str::lower((string) ($plan?->codigo ?? ''));
        if (Str::contains($code, 'personalizado')) return 'custom';
        if (Str::contains($code, 'empresa')) return 'enterprise';
        if (Str::contains($code, 'profesional')) return 'professional';
        return 'initial';
    }
}
