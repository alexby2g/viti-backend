<?php

namespace App\Http\Controllers;

use App\Models\{Cliente,Conversacion,Cuestionario,Empresa,SolicitudRespuesta,SolicitudSistema};
use App\Support\{Audit,Code};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicNoPlanApplicationController extends Controller
{
    private const OPERATION_QUESTION_NUMBERS = [12, 13, 17, 18, 19, 27, 32, 35, 70];
    private const GENERIC_OPERATION_NUMBERS = [12, 13, 17, 18, 19];

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
            'declaracion_aceptada' => ['required','accepted'],
            'declaracion_nombre' => ['required','string','min:3','max:180'],
            'declaracion_fecha' => ['required','date'],
            'respuestas' => ['required','array'],
            'respuestas.*.pregunta_id' => ['required','integer'],
            'respuestas.*.valor' => ['nullable'],
        ]);

        $questionnaire = Cuestionario::query()
            ->where('activo', true)
            ->with(['secciones.preguntas'])
            ->latest('id')
            ->firstOrFail();

        $questions = $questionnaire->secciones->flatMap(fn ($section) => $section->preguntas)->keyBy('id');
        $incoming = collect($data['respuestas'])->keyBy(fn ($item) => (int) $item['pregunta_id']);

        foreach ($questions as $question) {
            if (!$question->obligatoria) continue;
            $number = (int) $question->numero;
            if (in_array($number, self::OPERATION_QUESTION_NUMBERS, true) && !in_array($number, self::GENERIC_OPERATION_NUMBERS, true)) {
                continue;
            }
            $item = $incoming->get((int) $question->id);
            $value = $item['valor'] ?? null;
            $filled = is_array($value) ? count($value) > 0 : strlen(trim((string) $value)) > 0;
            abort_unless($filled, 422, "Completa la pregunta {$question->numero} antes de enviar la evaluación.");
        }

        [$solicitud, $cliente, $empresa] = DB::transaction(function () use ($data, $questionnaire, $questions, $incoming): array {
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
                    'canal_origen' => 'viti_web_unificado_sin_plan',
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
                'plan_viti_id' => null,
                'presupuesto_estimado' => null,
                'forma_pago_preferida' => null,
                'frecuencia_suscripcion_preferida' => null,
                'acuerdo_comercial_requerido' => false,
                'acuerdo_comercial_aceptado' => false,
                'declaracion_aceptada' => true,
                'declaracion_nombre' => $data['declaracion_nombre'],
                'declaracion_fecha' => $data['declaracion_fecha'],
                'enviado_at' => now(),
            ]);

            foreach ($incoming as $item) {
                $question = $questions->get((int) $item['pregunta_id']);
                abort_unless($question, 422, 'Una respuesta no pertenece al cuestionario de VITI.');
                if (in_array((int) $question->numero, self::OPERATION_QUESTION_NUMBERS, true)
                    && !in_array((int) $question->numero, self::GENERIC_OPERATION_NUMBERS, true)) {
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
                ['asunto' => 'Evaluación de '.$solicitud->codigo, 'estado' => 'abierta', 'ultimo_mensaje_at' => now()]
            );

            return [$solicitud, $cliente, $empresa];
        });

        Audit::log($request, 'solicitud_viti_sin_plan_enviada', $solicitud, 'Se recibió una solicitud VITI sin plan seleccionado para recomendar una solución después del cuestionario.', [
            'plan' => null,
            'cliente_id' => $cliente->id,
            'empresa_id' => $empresa->id,
        ]);

        return response()->json([
            'message' => 'Evaluación recibida. AGR Studio recomendará el plan y la solución después de revisar tus respuestas.',
            'data' => [
                'codigo' => $solicitud->codigo,
                'estado' => 'en_revision',
                'plan' => null,
                'correo' => $cliente->correo,
                'empresa' => $empresa->nombre_comercial,
            ],
        ], 201);
    }
}
