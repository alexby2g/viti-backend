<?php

namespace App\Http\Controllers;

use App\Models\{Empresa,Proyecto,ProyectoAvance,SolicitudSistema};
use App\Services\WorkflowStateService;
use App\Support\{Audit,Code};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProyectoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query=Proyecto::with(['empresa:id,nombre_comercial','cliente:id,nombre,telefono','responsable:id,nombre,apellido'])->latest();
        if($request->filled('fase'))$query->where('fase',$request->string('fase'));
        if($request->filled('buscar')){$term='%'.$request->string('buscar').'%';$query->where(fn($q)=>$q->where('codigo','like',$term)->orWhere('nombre','like',$term)->orWhereHas('empresa',fn($e)=>$e->where('nombre_comercial','like',$term)));}
        return response()->json($query->paginate(min(max((int)$request->input('per_page',20),1),100)));
    }

    public function store(Request $request): JsonResponse
    {
        $data=$this->validateData($request);
        $data['fase']=$data['fase']??'levantamiento';
        $data['estado']=$data['estado']??'activo';
        $data['progreso']=$data['progreso']??0;
        $this->assertCompanyClient((int)$data['empresa_id'],(int)$data['cliente_id']);
        app(WorkflowStateService::class)->assertProyectoIntegrity(null,null,$data['fase'],$data['estado'],(int)$data['progreso']);

        $proyecto=DB::transaction(function()use($data,$request){
            if(!empty($data['solicitud_id'])){
                $solicitud=SolicitudSistema::with('planViti')->lockForUpdate()->findOrFail($data['solicitud_id']);
                abort_unless((int)$solicitud->empresa_id === (int)$data['empresa_id'] && (int)$solicitud->cliente_id === (int)$data['cliente_id'],422,'La solicitud no corresponde a la empresa o responsable seleccionados.');
                abort_unless($solicitud->estado === 'aprobada',422,'La solicitud debe estar aprobada antes de convertirse en proyecto.');
                app(WorkflowStateService::class)->assertSolicitudTransition($solicitud->estado,'convertida');
            }

            $p=Proyecto::create($data+['codigo'=>Code::next('proyectos','PRO')]);

            if(!empty($data['solicitud_id'])){
                if($solicitud->plan_viti_id){
                    $solicitud->empresa()->update(['plan_viti_id'=>$solicitud->plan_viti_id]);
                }
                // El observer de Solicitud exige que el proyecto exista antes de marcar
                // la solicitud como convertida. En este punto la relación ya es real.
                $solicitud->update(['estado'=>'convertida','aprobado_at'=>$solicitud->aprobado_at ?: now()]);
            }

            ProyectoAvance::create(['proyecto_id'=>$p->id,'creado_por'=>$request->user()->id,'fase'=>$p->fase,'titulo'=>'Proyecto creado','descripcion'=>'Se inició el proyecto en VITI.','progreso'=>$p->progreso]);
            return $p;
        });
        Audit::log($request,'proyecto_creado',$proyecto,'Se creó un proyecto de desarrollo.');
        return response()->json(['data'=>$this->withWorkflow($proyecto->load(['empresa','cliente']))],201);
    }

    public function show(Proyecto $proyecto): JsonResponse
    {
        $proyecto->load(['empresa','cliente','solicitud.planViti','responsable','avances.creador','avances.archivos','aplicacion','archivos']);
        return response()->json(['data'=>$this->withWorkflow($proyecto)]);
    }

    public function update(Request $request, Proyecto $proyecto): JsonResponse
    {
        $data=$this->validateData($request,$proyecto);
        $this->assertStructuralLinks($proyecto,$data);
        $this->assertCompanyClient((int)$proyecto->empresa_id,(int)$proyecto->cliente_id);

        $workflow=app(WorkflowStateService::class);
        $targetPhase=$data['fase']??$proyecto->fase;
        $targetState=$data['estado']??$proyecto->estado;
        $targetProgress=(int)($data['progreso']??$proyecto->progreso);
        $workflow->assertProyectoPhaseTransition($proyecto->fase,$targetPhase);
        $workflow->assertProyectoStateTransition($proyecto->estado,$targetState);
        $workflow->assertProyectoIntegrity($proyecto->fase,$proyecto->estado,$targetPhase,$targetState,$targetProgress);

        $proyecto->update($data);
        Audit::log($request,'proyecto_actualizado',$proyecto,'Se actualizó el proyecto.');
        return response()->json(['data'=>$this->withWorkflow($proyecto->fresh()->load(['empresa','cliente']))]);
    }

    public function addProgress(Request $request, Proyecto $proyecto): JsonResponse
    {
        $data=$request->validate([
            'fase'=>['required',Rule::in($this->phases())],
            'area'=>['nullable',Rule::in(['general','analisis','diseno','frontend','backend','movil','infraestructura','qa'])],
            'titulo'=>['required','string','max:200'],
            'descripcion'=>['nullable','string','max:5000'],
            'progreso'=>['nullable','integer','min:0','max:100'],
            'visible_cliente'=>['nullable','boolean'],
        ]);

        $workflow=app(WorkflowStateService::class);
        $targetState=match($data['fase']){
            'finalizado'=>'finalizado',
            'mantenimiento'=>'mantenimiento',
            default=>$proyecto->estado,
        };
        $targetProgress=$data['fase']==='finalizado'
            ? 100
            : (int)($data['progreso']??$proyecto->progreso);

        $workflow->assertProyectoPhaseTransition($proyecto->fase,$data['fase']);
        $workflow->assertProyectoStateTransition($proyecto->estado,$targetState);
        $workflow->assertProyectoIntegrity($proyecto->fase,$proyecto->estado,$data['fase'],$targetState,$targetProgress);

        $data['progreso']=$targetProgress;
        $avance=ProyectoAvance::create($data+['proyecto_id'=>$proyecto->id,'creado_por'=>$request->user()->id]);
        $proyecto->update([
            'fase'=>$data['fase'],
            'estado'=>$targetState,
            'progreso'=>$targetProgress,
        ]);
        Audit::log($request,'avance_registrado',$proyecto,'Se registró un avance del proyecto.');
        return response()->json(['data'=>$avance,'workflow'=>$workflow->proyectoSnapshot($proyecto->fase,$proyecto->estado)],201);
    }

    public function destroy(Request $request, Proyecto $proyecto): JsonResponse
    {
        abort_if($proyecto->aplicacion()->exists(),422,'No se puede eliminar un proyecto con aplicación integrada.');
        abort_if($proyecto->solicitud_id,422,'No se puede eliminar un proyecto originado en una solicitud convertida. Cancélalo o ciérralo para conservar la trazabilidad.');
        $proyecto->delete(); Audit::log($request,'proyecto_eliminado',$proyecto,'Proyecto enviado a papelera.');
        return response()->json(status:204);
    }

    private function validateData(Request $request,?Proyecto $proyecto=null):array
    {
        $workflow=app(WorkflowStateService::class);
        return $request->validate([
            'solicitud_id'=>['nullable','integer','exists:solicitudes_sistema,id',Rule::unique('proyectos','solicitud_id')->ignore($proyecto?->id)],
            'empresa_id'=>['required','integer','exists:empresas,id'],
            'cliente_id'=>['required','integer','exists:clientes,id'],
            'responsable_id'=>['nullable','integer','exists:usuarios,id'],
            'nombre'=>['required','string','max:200'],
            'descripcion'=>['nullable','string','max:5000'],
            'fase'=>['nullable',Rule::in($workflow->proyectoPhases())],
            'estado'=>['nullable',Rule::in($workflow->proyectoStates())],
            'progreso'=>['nullable','integer','min:0','max:100'],
            'fecha_inicio'=>['nullable','date'],'fecha_beta'=>['nullable','date'],'fecha_entrega'=>['nullable','date'],
            'repositorio_url'=>['nullable','url','max:255'],'produccion_url'=>['nullable','url','max:255'],'observaciones'=>['nullable','string','max:5000'],
        ]);
    }

    private function assertStructuralLinks(Proyecto $proyecto,array $data):void
    {
        $fields=[
            'empresa_id'=>(int)$proyecto->empresa_id,
            'cliente_id'=>(int)$proyecto->cliente_id,
            'solicitud_id'=>$proyecto->solicitud_id===null?null:(int)$proyecto->solicitud_id,
        ];

        foreach($fields as $field=>$current){
            if(!array_key_exists($field,$data))continue;
            $incoming=$data[$field]===null?null:(int)$data[$field];
            if($incoming!==$current){
                throw ValidationException::withMessages([
                    $field=>'Este vínculo es estructural y no puede cambiarse después de crear el proyecto.',
                ]);
            }
        }
    }

    private function assertCompanyClient(int $empresaId,int $clienteId):void
    {
        $empresa=Empresa::query()->findOrFail($empresaId);
        if($empresa->cliente_id!==null && (int)$empresa->cliente_id!==$clienteId){
            throw ValidationException::withMessages([
                'cliente_id'=>'El cliente seleccionado no corresponde a la empresa del proyecto.',
            ]);
        }
    }

    private function phases():array{return app(WorkflowStateService::class)->proyectoPhases();}

    private function withWorkflow(Proyecto $proyecto): Proyecto
    {
        $proyecto->setAttribute('workflow',app(WorkflowStateService::class)->proyectoSnapshot($proyecto->fase,$proyecto->estado));
        return $proyecto;
    }
}
