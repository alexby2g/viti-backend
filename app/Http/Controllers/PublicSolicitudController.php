<?php

namespace App\Http\Controllers;

use App\Models\{Cliente,Conversacion,Cuestionario,Empresa,PlanViti,SolicitudRespuesta,SolicitudSistema};
use App\Support\Code;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PublicSolicitudController extends Controller
{
    private const PAYMENT_OPTIONS = ['contado','50_50','tres_partes','por_definir'];

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
            'message' => 'Tus datos fueron registrados. Ahora completa el diagnóstico para que VITI pueda orientarte hacia el plan adecuado.',
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
            'empresa:id,nombre_comercial,actividad,telefono,whatsapp,logo_path',
            'cliente:id,nombre,telefono,whatsapp',
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
        $solicitud = $this->resolve($token);
        abort_if(in_array($solicitud->estado, ['aprobada','convertida','cerrada'], true), 422, 'Esta solicitud ya no admite cambios.');
        $data = $request->validate([
            'respuestas'=>['required','array'],
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
        ]);

        DB::transaction(function () use ($data, $solicitud): void {
            foreach ($data['respuestas'] as $item) {
                $value = $item['valor'] ?? null;
                SolicitudRespuesta::updateOrCreate(
                    ['solicitud_id'=>$solicitud->id,'pregunta_id'=>$item['pregunta_id']],
                    is_array($value)
                        ? ['respuesta_json'=>$value,'respuesta_texto'=>null,'origen'=>'cliente']
                        : ['respuesta_texto'=>$value===null?null:(string)$value,'respuesta_json'=>null,'origen'=>'cliente']
                );
            }

            $selectedPlan = !empty($data['plan_viti_id']) ? PlanViti::query()->whereKey($data['plan_viti_id'])->where('activo', true)->first() : null;
            abort_if(!empty($data['plan_viti_id']) && !$selectedPlan, 422, 'El plan seleccionado ya no está disponible.');

            $solicitud->update([
                'declaracion_aceptada'=>(bool)($data['declaracion_aceptada']??false),
                'declaracion_nombre'=>$data['declaracion_nombre']??null,
                'declaracion_fecha'=>$data['declaracion_fecha']??null,
                'plan_viti_id'=>$selectedPlan?->id,
                'presupuesto_estimado'=>$selectedPlan?->precio_proyecto,
                'forma_pago_preferida'=>$data['forma_pago_preferida']??null,
                'frecuencia_suscripcion_preferida'=>$data['frecuencia_suscripcion_preferida']??null,
                'acuerdo_comercial_aceptado'=>(bool)($data['acuerdo_comercial_aceptado']??false),
                'acuerdo_comercial_nombre'=>$data['acuerdo_comercial_nombre']??null,
                'acuerdo_comercial_fecha'=>$data['acuerdo_comercial_fecha']??null,
            ]);
        });

        ClientPortalController::syncCompany($solicitud->fresh(), $solicitud->cliente);
        $solicitud->cliente?->update(['estado'=>'formulario_en_proceso']);
        return response()->json(['message'=>'Tus respuestas fueron guardadas.']);
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $solicitud = $this->resolve($token)->load('planViti');
        $requiredIds = $solicitud->cuestionario->secciones()->with('preguntas')->get()->flatMap(fn($s)=>$s->preguntas)->where('obligatoria',true)->pluck('id');
        $answered = $solicitud->respuestas()->whereIn('pregunta_id',$requiredIds)->get()->filter(fn($a)=>filled($a->respuesta_texto)||!empty($a->respuesta_json))->pluck('pregunta_id');
        abort_if($requiredIds->diff($answered)->isNotEmpty(),422,'Completa las preguntas obligatorias.');
        abort_unless($solicitud->declaracion_aceptada && filled($solicitud->declaracion_nombre) && $solicitud->declaracion_fecha,422,'Debes aceptar la declaración final.');

        if ($solicitud->acuerdo_comercial_requerido) {
            abort_unless($solicitud->plan_viti_id,422,'Selecciona el plan que prefieres para tu proyecto.');
            abort_unless(filled($solicitud->forma_pago_preferida),422,'Selecciona una forma de pago preferida.');
            if ($solicitud->planViti && ($solicitud->planViti->precio_mensual !== null || $solicitud->planViti->precio_anual !== null)) {
                abort_unless(filled($solicitud->frecuencia_suscripcion_preferida),422,'Selecciona si prefieres la suscripción mensual o anual.');
            }
            abort_unless($solicitud->acuerdo_comercial_aceptado && filled($solicitud->acuerdo_comercial_nombre) && $solicitud->acuerdo_comercial_fecha,422,'Debes aceptar el acuerdo comercial inicial para enviar la solicitud.');
        }

        ClientPortalController::syncCompany($solicitud->fresh(), $solicitud->cliente);
        $solicitud->update(['estado'=>'en_revision','enviado_at'=>now()]);
        $solicitud->cliente?->update(['estado'=>'informacion_recibida']);
        Conversacion::firstOrCreate(
            ['cliente_id'=>$solicitud->cliente_id,'solicitud_id'=>$solicitud->id],
            ['asunto'=>'Revisión de '.$solicitud->codigo,'estado'=>'abierta','ultimo_mensaje_at'=>now()]
        );
        return response()->json(['message'=>'Solicitud enviada. El equipo de VITI revisará la información y podrá responderte desde tu buzón.']);
    }

    private function resolve(string $token): SolicitudSistema
    {
        return SolicitudSistema::query()->where('public_token',$token)->where('publico_habilitado',true)->firstOrFail();
    }
}
