<?php

namespace App\Http\Controllers;

use App\Models\{Cliente,Conversacion,Cuestionario,Empresa,PlanViti,SolicitudRespuesta,SolicitudSistema,Usuario};
use App\Support\Code;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicSolicitudController extends Controller
{
    private const PAYMENT_OPTIONS = ['contado','50_50','tres_partes','por_definir'];
    private const OPERATION_QUESTION_NUMBERS = [12,13,17,18,19,27,32,35,70];

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required','string','min:3','max:180'],
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
        ], [
            'nombre.required' => 'Ingresa tu nombre completo.',
            'telefono.required' => 'Ingresa tu número de teléfono.',
            'telefono.regex' => 'El teléfono debe contener entre 7 y 15 dígitos.',
            'whatsapp.regex' => 'El número de WhatsApp debe contener entre 7 y 15 dígitos.',
            'ciudad.required' => 'Indica tu ciudad o localidad.',
            'empresa_nombre.required' => 'Ingresa el nombre de tu negocio, institución o proyecto.',
            'titulo_sistema.required' => 'Escribe brevemente qué sistema necesitas.',
        ]);

        $questionnaireId = Cuestionario::query()->where('activo', true)->value('id');
        abort_unless($questionnaireId, 422, 'VITI no tiene un cuestionario activo en este momento.');

        [$cliente, $empresa, $solicitud] = DB::transaction(function () use ($data, $questionnaireId): array {
            $documento = filled($data['documento'] ?? null) ? trim($data['documento']) : null;
            $cliente = Cliente::query()->where('telefono', $data['telefono'])->first();

            if ($cliente) {
                if ($documento) {
                    abort_if(Cliente::query()->where('documento', $documento)->whereKeyNot($cliente->id)->exists(),422,'Ese documento ya pertenece a otro cliente.');
                    abort_if(filled($cliente->documento) && $cliente->documento !== $documento,422,'El documento indicado no coincide con el cliente registrado para ese teléfono.');
                }
                $cliente->update([
                    'whatsapp' => $cliente->whatsapp ?: ($data['whatsapp'] ?? $data['telefono']),
                    'documento' => $cliente->documento ?: $documento,
                    'ci_expedido' => $cliente->ci_expedido ?: ($data['ci_expedido'] ?? null),
                    'ciudad' => $cliente->ciudad ?: trim($data['ciudad']),
                    'direccion' => $cliente->direccion ?: ($data['direccion'] ?? null),
                    'estado' => 'formulario_en_proceso',
                ]);
            } else {
                abort_if($documento && Cliente::query()->where('documento', $documento)->exists(),422,'Ese documento ya está registrado con otro número de teléfono.');
                $cliente = Cliente::create([
                    'nombre' => trim($data['nombre']),
                    'telefono' => $data['telefono'],
                    'whatsapp' => $data['whatsapp'] ?? $data['telefono'],
                    'documento' => $documento,
                    'ci_expedido' => $data['ci_expedido'] ?? null,
                    'ciudad' => trim($data['ciudad']),
                    'direccion' => $data['direccion'] ?? null,
                    'estado' => 'formulario_en_proceso',
                    'canal_origen' => 'viti',
                ]);
            }

            $empresaNombre = trim($data['empresa_nombre']);
            $empresa = Empresa::query()->where('cliente_id', $cliente->id)->where('nombre_comercial', $empresaNombre)->first();
            if ($empresa) {
                $empresa->update([
                    'actividad' => $empresa->actividad ?: ($data['empresa_actividad'] ?? null),
                    'telefono' => $empresa->telefono ?: ($data['empresa_telefono'] ?? $data['telefono']),
                    'whatsapp' => $empresa->whatsapp ?: ($data['empresa_whatsapp'] ?? ($data['whatsapp'] ?? $data['telefono'])),
                    'ciudad' => $empresa->ciudad ?: ($data['empresa_ciudad'] ?? $data['ciudad']),
                    'direccion' => $empresa->direccion ?: ($data['empresa_direccion'] ?? $data['direccion'] ?? null),
                ]);
            } else {
                $empresa = Empresa::create([
                    'cliente_id' => $cliente->id,
                    'codigo' => Code::next('empresas','EMP'),
                    'nombre_comercial' => $empresaNombre,
                    'actividad' => $data['empresa_actividad'] ?? null,
                    'telefono' => $data['empresa_telefono'] ?? $data['telefono'],
                    'whatsapp' => $data['empresa_whatsapp'] ?? ($data['whatsapp'] ?? $data['telefono']),
                    'ciudad' => $data['empresa_ciudad'] ?? $data['ciudad'],
                    'direccion' => $data['empresa_direccion'] ?? $data['direccion'] ?? null,
                    'estado' => 'pendiente_revision',
                ]);
            }

            $solicitud = SolicitudSistema::create([
                'empresa_id' => $empresa->id,
                'cliente_id' => $cliente->id,
                'cuestionario_id' => $questionnaireId,
                'codigo' => Code::next('solicitudes_sistema','SOL'),
                'public_token' => Str::random(48),
                'publico_habilitado' => true,
                'titulo' => trim($data['titulo_sistema']),
                'resumen' => $data['resumen'] ?? null,
                'estado' => 'borrador',
                'prioridad' => 'normal',
                'acuerdo_comercial_requerido' => true,
            ]);

            return [$cliente, $empresa, $solicitud];
        });

        return response()->json([
            'message' => 'Tus datos fueron registrados. Ahora elige el plan VITI que mejor se ajuste a tu negocio.',
            'data' => [
                'cliente' => $cliente,
                'empresa' => $empresa,
                'solicitud_codigo' => $solicitud->codigo,
                'solicitud_token' => $solicitud->public_token,
                'ruta_cuestionario' => '/solicitar/'.$solicitud->public_token,
            ],
        ], 201);
    }

    public function show(string $token): JsonResponse
    {
        $solicitud = $this->resolve($token)->load([
            'empresa:id,nombre_comercial,actividad,telefono,whatsapp,ciudad,direccion,logo_path',
            'cliente:id,nombre,telefono,whatsapp,ciudad,direccion',
            'cuestionario.secciones.preguntas',
            'respuestas.pregunta',
            'planViti:id,codigo,nombre,descripcion,precio_proyecto,precio_mensual,precio_anual,dias_prueba,modulos,max_usuarios,max_aplicaciones',
        ]);

        $data = $solicitud->toArray();
        $data['planes_disponibles'] = PlanViti::query()
            ->where('activo', true)
            ->orderByRaw('precio_proyecto is null')
            ->orderBy('precio_proyecto')
            ->orderBy('id')
            ->get(['id','codigo','nombre','descripcion','precio_proyecto','precio_mensual','precio_anual','dias_prueba','modulos','max_usuarios','max_aplicaciones'])
            ->values();

        return response()->json(['data'=>$data]);
    }

    public function save(Request $request, string $token): JsonResponse
    {
        $solicitud = $this->resolve($token)->loadMissing(['cliente','empresa','planViti','cuestionario.secciones.preguntas']);
        abort_if(in_array($solicitud->estado, ['aprobada','convertida','cerrada'], true), 422, 'Esta solicitud ya no admite cambios.');

        $data = $request->validate([
            'respuestas'=>['sometimes','array'],
            'respuestas.*.pregunta_id'=>['required','integer','exists:cuestionario_preguntas,id'],
            'respuestas.*.valor'=>['nullable'],
            'declaracion_aceptada'=>['nullable','boolean'],
            'declaracion_nombre'=>['nullable','string','max:180'],
            'declaracion_fecha'=>['nullable','date'],
            'plan_viti_id'=>['nullable','integer','exists:planes_viti,id'],
            'forma_pago_preferida'=>['nullable',Rule::in(self::PAYMENT_OPTIONS)],
            'frecuencia_suscripcion_preferida'=>['nullable',Rule::in(['mensual','anual'])],
            'acuerdo_comercial_aceptado'=>['nullable','boolean'],
            'acuerdo_comercial_nombre'=>['nullable','string','max:180'],
            'acuerdo_comercial_fecha'=>['nullable','date'],
            'registro'=>['sometimes','array'],
            'registro.cliente_nombre'=>['sometimes','string','min:3','max:180'],
            'registro.cliente_whatsapp'=>['nullable','regex:/^[0-9]{7,15}$/'],
            'registro.cliente_ciudad'=>['sometimes','string','max:100'],
            'registro.cliente_direccion'=>['nullable','string','max:255'],
            'registro.empresa_nombre'=>['sometimes','string','min:2','max:180'],
            'registro.empresa_actividad'=>['nullable','string','max:200'],
            'registro.empresa_telefono'=>['nullable','string','max:30'],
            'registro.empresa_whatsapp'=>['nullable','string','max:30'],
            'registro.empresa_ciudad'=>['nullable','string','max:100'],
            'registro.empresa_direccion'=>['nullable','string','max:255'],
            'registro.titulo_sistema'=>['sometimes','string','min:3','max:200'],
            'registro.resumen'=>['nullable','string','max:5000'],
        ], [
            'registro.cliente_nombre.min'=>'Escribe el nombre completo de quien solicita.',
            'registro.cliente_whatsapp.regex'=>'El WhatsApp debe contener entre 7 y 15 dígitos.',
            'registro.empresa_nombre.min'=>'Escribe el nombre del negocio o proyecto.',
            'registro.titulo_sistema.min'=>'Describe brevemente el sistema que necesitas.',
        ]);

        $selectedPlanId = array_key_exists('plan_viti_id', $data) ? $data['plan_viti_id'] : $solicitud->plan_viti_id;
        $selectedPlan = $selectedPlanId
            ? PlanViti::query()->whereKey($selectedPlanId)->where('activo', true)->first()
            : null;
        abort_if($selectedPlanId && !$selectedPlan, 422, 'El plan seleccionado ya no está disponible.');

        $planChanged = (int)($solicitud->plan_viti_id ?? 0) !== (int)($selectedPlan?->id ?? 0);
        $paymentExplicit = array_key_exists('forma_pago_preferida', $data);
        $payment = $paymentExplicit ? ($data['forma_pago_preferida'] ?? null) : $solicitud->forma_pago_preferida;
        if ($payment && $selectedPlan && !in_array($payment, $this->allowedPayments($selectedPlan), true)) {
            if ($paymentExplicit) {
                throw ValidationException::withMessages([
                    'forma_pago_preferida' => 'La forma de pago seleccionada no corresponde al plan VITI elegido.',
                ]);
            }
            $payment = null;
        }

        $frequencyExplicit = array_key_exists('frecuencia_suscripcion_preferida', $data);
        $frequency = $frequencyExplicit ? ($data['frecuencia_suscripcion_preferida'] ?? null) : $solicitud->frecuencia_suscripcion_preferida;
        if ($selectedPlan && $this->planKey($selectedPlan) === 'custom') {
            $frequency = null;
        }

        DB::transaction(function () use ($data, $solicitud, $selectedPlan, $planChanged, $payment, $paymentExplicit, $frequency, $frequencyExplicit): void {
            $questionMap = $solicitud->cuestionario->secciones
                ->flatMap(fn($section) => $section->preguntas)
                ->keyBy('id');
            $allowedNumbers = $this->allowedOperationNumbers($selectedPlan);

            foreach (($data['respuestas'] ?? []) as $item) {
                $question = $questionMap->get((int)$item['pregunta_id']);
                if (!$question) {
                    throw ValidationException::withMessages(['respuestas' => 'Una respuesta no pertenece al formulario de esta solicitud.']);
                }
                if (in_array((int)$question->numero, self::OPERATION_QUESTION_NUMBERS, true)
                    && !in_array((int)$question->numero, $allowedNumbers, true)) {
                    throw ValidationException::withMessages(['respuestas' => 'Una respuesta ya no corresponde al plan VITI seleccionado.']);
                }

                $value = $item['valor'] ?? null;
                SolicitudRespuesta::updateOrCreate(
                    ['solicitud_id'=>$solicitud->id,'pregunta_id'=>$item['pregunta_id']],
                    is_array($value)
                        ? ['respuesta_json'=>$value,'respuesta_texto'=>null,'origen'=>'cliente']
                        : ['respuesta_texto'=>$value===null?null:(string)$value,'respuesta_json'=>null,'origen'=>'cliente']
                );
            }

            if ($selectedPlan) {
                $obsoleteIds = $questionMap
                    ->filter(fn($question) => in_array((int)$question->numero, self::OPERATION_QUESTION_NUMBERS, true)
                        && !in_array((int)$question->numero, $allowedNumbers, true))
                    ->keys()
                    ->all();
                if ($obsoleteIds) {
                    $solicitud->respuestas()->whereIn('pregunta_id', $obsoleteIds)->delete();
                }
            }

            $updates = [];
            if (array_key_exists('declaracion_aceptada', $data)) $updates['declaracion_aceptada'] = (bool)$data['declaracion_aceptada'];
            if (array_key_exists('declaracion_nombre', $data)) $updates['declaracion_nombre'] = $data['declaracion_nombre'];
            if (array_key_exists('declaracion_fecha', $data)) $updates['declaracion_fecha'] = $data['declaracion_fecha'];
            if (array_key_exists('plan_viti_id', $data)) {
                $updates['plan_viti_id'] = $selectedPlan?->id;
                $updates['presupuesto_estimado'] = $selectedPlan?->precio_proyecto;
            }
            if ($paymentExplicit || $planChanged) $updates['forma_pago_preferida'] = $payment;
            if ($frequencyExplicit || $planChanged) $updates['frecuencia_suscripcion_preferida'] = $frequency;
            if (array_key_exists('acuerdo_comercial_aceptado', $data)) $updates['acuerdo_comercial_aceptado'] = (bool)$data['acuerdo_comercial_aceptado'];
            if (array_key_exists('acuerdo_comercial_nombre', $data)) $updates['acuerdo_comercial_nombre'] = $data['acuerdo_comercial_nombre'];
            if (array_key_exists('acuerdo_comercial_fecha', $data)) $updates['acuerdo_comercial_fecha'] = $data['acuerdo_comercial_fecha'];
            if ($updates) $solicitud->update($updates);

            $this->updateRegistration($solicitud, $data['registro'] ?? null);
        });

        if (!$solicitud->empresa_id) {
            ClientPortalController::syncCompany($solicitud->fresh(), $solicitud->cliente);
        }
        $solicitud->cliente?->update(['estado'=>'formulario_en_proceso']);
        return response()->json(['message'=>'Tus cambios fueron guardados.']);
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $solicitud = $this->resolve($token)->load('planViti');

        // El registro ya contiene la información principal. La configuración operativa
        // es deliberadamente opcional y puede completarse durante la revisión con AGR Studio.
        abort_unless($solicitud->declaracion_aceptada && filled($solicitud->declaracion_nombre) && $solicitud->declaracion_fecha,422,'Debes confirmar que los datos de tu solicitud son correctos.');

        if ($solicitud->acuerdo_comercial_requerido) {
            abort_unless($solicitud->plan_viti_id,422,'Selecciona el plan que prefieres para tu proyecto.');
            abort_unless(filled($solicitud->forma_pago_preferida),422,'Selecciona una forma de pago preferida.');
            abort_unless(
                in_array($solicitud->forma_pago_preferida, $this->allowedPayments($solicitud->planViti), true),
                422,
                'La forma de pago seleccionada no corresponde al plan VITI elegido.'
            );
            if ($solicitud->planViti && ($solicitud->planViti->precio_mensual !== null || $solicitud->planViti->precio_anual !== null)) {
                abort_unless(filled($solicitud->frecuencia_suscripcion_preferida),422,'Selecciona si prefieres la suscripción mensual o anual.');
            }
            abort_unless($solicitud->acuerdo_comercial_aceptado && filled($solicitud->acuerdo_comercial_nombre) && $solicitud->acuerdo_comercial_fecha,422,'Debes aceptar el acuerdo comercial inicial para enviar la solicitud.');
        }

        if (!$solicitud->empresa_id) {
            ClientPortalController::syncCompany($solicitud->fresh(), $solicitud->cliente);
        }
        abort_unless($solicitud->fresh()->empresa_id, 422, 'La solicitud debe estar asociada a un negocio antes de enviarse.');

        $solicitud->update(['estado'=>'en_revision','enviado_at'=>now()]);
        $solicitud->cliente?->update(['estado'=>'informacion_recibida']);
        Conversacion::firstOrCreate(
            ['cliente_id'=>$solicitud->cliente_id,'solicitud_id'=>$solicitud->id],
            ['asunto'=>'Revisión de '.$solicitud->codigo,'estado'=>'abierta','ultimo_mensaje_at'=>now()]
        );
        return response()->json(['message'=>'Solicitud enviada. El equipo de VITI revisará la información y podrá responderte desde tu buzón.']);
    }

    private function updateRegistration(SolicitudSistema $solicitud, ?array $registration): void
    {
        if (!$registration) return;

        $cliente = $solicitud->cliente;
        if ($cliente) {
            $clientUpdates = [];
            if (array_key_exists('cliente_nombre', $registration)) $clientUpdates['nombre'] = trim($registration['cliente_nombre']);
            if (array_key_exists('cliente_whatsapp', $registration)) $clientUpdates['whatsapp'] = $registration['cliente_whatsapp'] ?: null;
            if (array_key_exists('cliente_ciudad', $registration)) $clientUpdates['ciudad'] = trim((string)$registration['cliente_ciudad']);
            if (array_key_exists('cliente_direccion', $registration)) $clientUpdates['direccion'] = $registration['cliente_direccion'] ?: null;
            if ($clientUpdates) {
                $cliente->update($clientUpdates);
                if (isset($clientUpdates['nombre'])) {
                    Usuario::query()->where('cliente_id', $cliente->id)->update(['nombre'=>$clientUpdates['nombre']]);
                }
            }
        }

        $empresa = $solicitud->empresa;
        if ($empresa) {
            if (array_key_exists('empresa_nombre', $registration)) {
                $name = trim($registration['empresa_nombre']);
                $duplicate = Empresa::query()
                    ->where('cliente_id', $solicitud->cliente_id)
                    ->where('id', '!=', $empresa->id)
                    ->whereRaw('LOWER(nombre_comercial) = ?', [Str::lower($name)])
                    ->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['registro.empresa_nombre'=>'Ya tienes otro negocio registrado con ese nombre.']);
                }
            }

            $companyUpdates = [];
            $map = [
                'empresa_nombre'=>'nombre_comercial',
                'empresa_actividad'=>'actividad',
                'empresa_telefono'=>'telefono',
                'empresa_whatsapp'=>'whatsapp',
                'empresa_ciudad'=>'ciudad',
                'empresa_direccion'=>'direccion',
            ];
            foreach ($map as $source => $target) {
                if (array_key_exists($source, $registration)) {
                    $value = is_string($registration[$source]) ? trim($registration[$source]) : $registration[$source];
                    $companyUpdates[$target] = $value === '' ? null : $value;
                }
            }
            if ($companyUpdates) $empresa->update($companyUpdates);
        }

        $requestUpdates = [];
        if (array_key_exists('titulo_sistema', $registration)) $requestUpdates['titulo'] = trim($registration['titulo_sistema']);
        if (array_key_exists('resumen', $registration)) $requestUpdates['resumen'] = $registration['resumen'] ?: null;
        if ($requestUpdates) $solicitud->update($requestUpdates);
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
        $code = Str::lower((string)($plan?->codigo ?? ''));
        if (Str::contains($code, 'personalizado')) return 'custom';
        if (Str::contains($code, 'empresa')) return 'enterprise';
        if (Str::contains($code, 'profesional')) return 'professional';
        return 'initial';
    }

    private function resolve(string $token): SolicitudSistema
    {
        return SolicitudSistema::query()->where('public_token',$token)->where('publico_habilitado',true)->firstOrFail();
    }
}
