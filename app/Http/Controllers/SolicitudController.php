<?php

namespace App\Http\Controllers;

use App\Models\{Auditoria,Cuestionario,Empresa,SolicitudRespuesta,SolicitudSistema};
use App\Services\{AccessInvitationService,WorkflowStateService};
use App\Support\{Audit,Code};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SolicitudController extends Controller
{
    private const PAYMENT_OPTIONS = ['contado','50_50','tres_partes','por_definir'];

    public function index(Request $request): JsonResponse
    {
        $query=SolicitudSistema::with(['empresa:id,nombre_comercial','cliente:id,nombre,telefono','asignado:id,nombre,apellido','planViti:id,nombre,precio_proyecto,precio_mensual,precio_anual'])->latest();
        if($request->filled('cliente_id'))$query->where('cliente_id',(int)$request->input('cliente_id'));
        if($request->filled('empresa_id'))$query->where('empresa_id',(int)$request->input('empresa_id'));
        if($request->filled('estado'))$query->where('estado',$request->string('estado'));
        if($request->filled('buscar')){
            $term='%'.$request->string('buscar').'%';
            $query->where(fn($q)=>$q
                ->where('codigo','like',$term)
                ->orWhere('titulo','like',$term)
                ->orWhereHas('empresa',fn($e)=>$e->where('nombre_comercial','like',$term))
                ->orWhereHas('cliente',fn($c)=>$c->where('nombre','like',$term)->orWhere('telefono','like',$term))
            );
        }
        return response()->json($query->paginate(min(max((int)$request->input('per_page',20),1),100)));
    }

    public function store(Request $request): JsonResponse
    {
        $data=$this->validateData($request);
        abort_unless(Empresa::query()->whereKey($data['empresa_id'])->where('cliente_id',$data['cliente_id'])->exists(),422,'La empresa seleccionada no pertenece al cliente indicado.');
        $data['cuestionario_id']=$data['cuestionario_id']??Cuestionario::where('activo',true)->value('id');
        abort_unless($data['cuestionario_id'],422,'No existe un cuestionario activo.');
        $data['estado']=$data['estado']??'borrador';
        $solicitud=SolicitudSistema::create($data+['codigo'=>Code::next('solicitudes_sistema','SOL'),'public_token'=>Str::random(48),'publico_habilitado'=>true]);
        Audit::log($request,'solicitud_creada',$solicitud,'Se creó una solicitud de sistema.');
        return response()->json(['data'=>$this->withWorkflow($solicitud->load(['empresa','cliente','planViti']))],201);
    }

    public function show(SolicitudSistema $solicitud): JsonResponse
    {
        $solicitud->load(['empresa','cliente.usuario','planViti','cuestionario.secciones.preguntas','respuestas.pregunta','proyecto','conversacion:id,solicitud_id,cliente_id,empresa_id,asunto,estado','archivos']);
        $solicitud->setAttribute('acceso_cliente', app(AccessInvitationService::class)->snapshotForSolicitud($solicitud));
        return response()->json(['data'=>$this->withWorkflow($solicitud)]);
    }

    public function update(Request $request, SolicitudSistema $solicitud): JsonResponse
    {
        $data=$this->validateData($request,$solicitud);
        $this->assertStructuralLinks($solicitud,$data);
        abort_unless(Empresa::query()->whereKey($data['empresa_id'])->where('cliente_id',$data['cliente_id'])->exists(),422,'La empresa seleccionada no pertenece al cliente indicado.');
        app(WorkflowStateService::class)->assertSolicitudTransition($solicitud->estado,$data['estado']??$solicitud->estado);
        $solicitud->update($data);
        Audit::log($request,'solicitud_actualizada',$solicitud,'Se actualizaron los datos generales de la solicitud.');
        return response()->json(['data'=>$this->withWorkflow($solicitud->fresh()->load(['empresa','cliente','planViti']))]);
    }

    public function saveAnswers(Request $request, SolicitudSistema $solicitud): JsonResponse
    {
        $data=$request->validate([
            'respuestas'=>['required','array'],
            'respuestas.*.pregunta_id'=>['required','integer','exists:cuestionario_preguntas,id'],
            'respuestas.*.valor'=>['nullable'],
            'declaracion_aceptada'=>['nullable','boolean'],
            'declaracion_nombre'=>['nullable','string','max:180'],
            'declaracion_fecha'=>['nullable','date'],
        ]);

        DB::transaction(function()use($data,$solicitud):void{
            foreach($data['respuestas'] as $item){
                $value=$item['valor']??null;
                $existing=SolicitudRespuesta::query()->where('solicitud_id',$solicitud->id)->where('pregunta_id',$item['pregunta_id'])->first();
                $origin=$existing?->origen ?: 'tecnico';
                SolicitudRespuesta::updateOrCreate(
                    ['solicitud_id'=>$solicitud->id,'pregunta_id'=>$item['pregunta_id']],
                    is_array($value)
                        ? ['respuesta_json'=>$value,'respuesta_texto'=>null,'origen'=>$origin]
                        : ['respuesta_texto'=>$value===null?null:(string)$value,'respuesta_json'=>null,'origen'=>$origin]
                );
            }
        });
        $solicitud->update([
            'declaracion_aceptada'=>(bool)($data['declaracion_aceptada']??false),
            'declaracion_nombre'=>$data['declaracion_nombre']??null,
            'declaracion_fecha'=>$data['declaracion_fecha']??null,
        ]);
        ClientPortalController::syncCompany($solicitud->fresh(),$solicitud->cliente);
        Audit::log($request,'cuestionario_guardado',$solicitud,'Se guardaron respuestas de la solicitud.');
        return response()->json(['message'=>'Respuestas guardadas.']);
    }

    public function submit(Request $request, SolicitudSistema $solicitud): JsonResponse
    {
        $this->validateCompletion($solicitud);
        app(WorkflowStateService::class)->assertSolicitudTransition($solicitud->estado,'en_revision');
        $solicitud->update(['estado'=>'en_revision','enviado_at'=>now()]);
        Audit::log($request,'solicitud_enviada',$solicitud,'La solicitud pasó a revisión.');
        return response()->json(['data'=>$this->withWorkflow($solicitud->fresh())]);
    }

    public function reject(Request $request, SolicitudSistema $solicitud): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required','string','min:5','max:1000'],
        ], [
            'motivo.required' => 'Indica el motivo antes de rechazar la solicitud.',
            'motivo.min' => 'Explica brevemente el motivo del rechazo.',
        ]);

        app(WorkflowStateService::class)->assertSolicitudTransition($solicitud->estado, 'rechazada');
        $solicitud->update(['estado' => 'rechazada', 'aprobado_at' => null]);
        Audit::log(
            $request,
            'solicitud_rechazada',
            $solicitud,
            'La solicitud fue rechazada por AGR Studio.',
            ['motivo' => trim($data['motivo'])]
        );

        return response()->json([
            'message' => 'Solicitud rechazada y registrada en el historial.',
            'data' => $this->withWorkflow($solicitud->fresh()->load(['empresa','cliente','planViti'])),
        ]);
    }

    public function timeline(SolicitudSistema $solicitud): JsonResponse
    {
        $events = Auditoria::query()
            ->with('usuario:id,nombre,apellido')
            ->where('entidad_tipo', SolicitudSistema::class)
            ->where('entidad_id', $solicitud->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id','usuario_id','accion','descripcion','datos','created_at']);

        return response()->json(['data' => $events]);
    }

    private function validateCompletion(SolicitudSistema $solicitud): void
    {
        abort_unless($solicitud->declaracion_aceptada && filled($solicitud->declaracion_nombre) && $solicitud->declaracion_fecha,422,'El cliente debe confirmar los datos de la solicitud.');
        if($solicitud->acuerdo_comercial_requerido){
            $solicitud->loadMissing('planViti');
            abort_unless($solicitud->plan_viti_id && filled($solicitud->forma_pago_preferida),422,'El cliente debe seleccionar plan y forma de pago.');
            if($solicitud->planViti && ($solicitud->planViti->precio_mensual!==null || $solicitud->planViti->precio_anual!==null)){
                abort_unless(filled($solicitud->frecuencia_suscripcion_preferida),422,'El cliente debe seleccionar modalidad mensual o anual de suscripción.');
            }
            abort_unless($solicitud->acuerdo_comercial_aceptado && filled($solicitud->acuerdo_comercial_nombre) && $solicitud->acuerdo_comercial_fecha,422,'El cliente debe aceptar el acuerdo comercial inicial.');
        }
    }

    public function destroy(Request $request, SolicitudSistema $solicitud): JsonResponse
    {
        abort_if($solicitud->proyecto()->exists(),422,'No se puede eliminar una solicitud con proyecto asociado.');
        $solicitud->delete(); Audit::log($request,'solicitud_eliminada',$solicitud,'Solicitud enviada a papelera.');
        return response()->json(status:204);
    }

    private function validateData(Request $request,?SolicitudSistema $solicitud=null):array
    {
        $workflow=app(WorkflowStateService::class);
        return $request->validate([
            'empresa_id'=>['required','integer','exists:empresas,id'],
            'cliente_id'=>['required','integer','exists:clientes,id'],
            'cuestionario_id'=>['nullable','integer','exists:cuestionarios,id'],
            'plan_viti_id'=>['nullable','integer','exists:planes_viti,id'],
            'asignado_a'=>['nullable','integer','exists:usuarios,id'],
            'titulo'=>['required','string','max:200'],
            'resumen'=>['nullable','string','max:5000'],
            'estado'=>['nullable',Rule::in($workflow->solicitudStates())],
            'prioridad'=>['nullable',Rule::in(['baja','normal','alta','urgente'])],
            'fecha_limite_deseada'=>['nullable','date'],
            'presupuesto_estimado'=>['nullable','numeric','min:0'],
            'forma_pago_preferida'=>['nullable',Rule::in(self::PAYMENT_OPTIONS)],
            'frecuencia_suscripcion_preferida'=>['nullable',Rule::in(['mensual','anual'])],
        ]);
    }

    private function assertStructuralLinks(SolicitudSistema $solicitud,array $data):void
    {
        if($solicitud->estado!=='borrador'){
            $locked=[
                'empresa_id'=>(int)$solicitud->empresa_id,
                'cliente_id'=>(int)$solicitud->cliente_id,
                'cuestionario_id'=>$solicitud->cuestionario_id===null?null:(int)$solicitud->cuestionario_id,
            ];
            foreach($locked as $field=>$current){
                if(!array_key_exists($field,$data))continue;
                $incoming=$data[$field]===null?null:(int)$data[$field];
                if($incoming!==$current){
                    throw ValidationException::withMessages([
                        $field=>'Este vínculo ya quedó fijado cuando la solicitud salió de borrador.',
                    ]);
                }
            }
        }

        if(in_array($solicitud->estado,['aprobada','convertida','cerrada'],true)
            && array_key_exists('plan_viti_id',$data)){
            $incoming=$data['plan_viti_id']===null?null:(int)$data['plan_viti_id'];
            $current=$solicitud->plan_viti_id===null?null:(int)$solicitud->plan_viti_id;
            if($incoming!==$current){
                throw ValidationException::withMessages([
                    'plan_viti_id'=>'El plan queda fijado una vez aprobada la solicitud.',
                ]);
            }
        }
    }

    private function withWorkflow(SolicitudSistema $solicitud): SolicitudSistema
    {
        $solicitud->setAttribute('workflow',app(WorkflowStateService::class)->solicitudSnapshot($solicitud->estado));
        return $solicitud;
    }
}
