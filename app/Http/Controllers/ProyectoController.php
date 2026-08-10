<?php

namespace App\Http\Controllers;

use App\Models\{Proyecto,ProyectoAvance,SolicitudSistema};
use App\Support\{Audit,Code};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        $proyecto=DB::transaction(function()use($data,$request){
            $p=Proyecto::create($data+['codigo'=>Code::next('proyectos','PRO')]);
            if(!empty($data['solicitud_id'])){
                $solicitud=SolicitudSistema::with('planViti')->findOrFail($data['solicitud_id']);
                abort_unless((int)$solicitud->empresa_id === (int)$data['empresa_id'] && (int)$solicitud->cliente_id === (int)$data['cliente_id'],422,'La solicitud no corresponde a la empresa o responsable seleccionados.');
                if($solicitud->plan_viti_id){
                    $solicitud->empresa()->update(['plan_viti_id'=>$solicitud->plan_viti_id]);
                }
                $solicitud->update(['estado'=>'convertida','aprobado_at'=>now()]);
            }
            ProyectoAvance::create(['proyecto_id'=>$p->id,'creado_por'=>$request->user()->id,'fase'=>$p->fase,'titulo'=>'Proyecto creado','descripcion'=>'Se inició el proyecto en VITI.','progreso'=>$p->progreso]);
            return $p;
        });
        Audit::log($request,'proyecto_creado',$proyecto,'Se creó un proyecto de desarrollo.');
        return response()->json(['data'=>$proyecto->load(['empresa','cliente'])],201);
    }

    public function show(Proyecto $proyecto): JsonResponse
    {
        return response()->json(['data'=>$proyecto->load(['empresa','cliente','solicitud.planViti','responsable','avances.creador','avances.archivos','aplicacion','archivos'])]);
    }

    public function update(Request $request, Proyecto $proyecto): JsonResponse
    {
        $data=$this->validateData($request,$proyecto);
        $proyecto->update($data);
        Audit::log($request,'proyecto_actualizado',$proyecto,'Se actualizó el proyecto.');
        return response()->json(['data'=>$proyecto->fresh()->load(['empresa','cliente'])]);
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
        $avance=ProyectoAvance::create($data+['proyecto_id'=>$proyecto->id,'creado_por'=>$request->user()->id]);
        $proyecto->update(array_filter(['fase'=>$data['fase'],'progreso'=>$data['progreso']??null],fn($v)=>$v!==null));
        Audit::log($request,'avance_registrado',$proyecto,'Se registró un avance del proyecto.');
        return response()->json(['data'=>$avance],201);
    }

    public function destroy(Request $request, Proyecto $proyecto): JsonResponse
    {
        abort_if($proyecto->aplicacion()->exists(),422,'No se puede eliminar un proyecto con aplicación integrada.');
        $proyecto->delete(); Audit::log($request,'proyecto_eliminado',$proyecto,'Proyecto enviado a papelera.');
        return response()->json(status:204);
    }

    private function validateData(Request $request,?Proyecto $proyecto=null):array
    {
        return $request->validate([
            'solicitud_id'=>['nullable','integer','exists:solicitudes_sistema,id',Rule::unique('proyectos','solicitud_id')->ignore($proyecto?->id)],
            'empresa_id'=>['required','integer','exists:empresas,id'],
            'cliente_id'=>['required','integer','exists:clientes,id'],
            'responsable_id'=>['nullable','integer','exists:usuarios,id'],
            'nombre'=>['required','string','max:200'],
            'descripcion'=>['nullable','string','max:5000'],
            'fase'=>['nullable',Rule::in($this->phases())],
            'estado'=>['nullable',Rule::in(['activo','pausado','finalizado','cancelado','mantenimiento'])],
            'progreso'=>['nullable','integer','min:0','max:100'],
            'fecha_inicio'=>['nullable','date'],'fecha_beta'=>['nullable','date'],'fecha_entrega'=>['nullable','date'],
            'repositorio_url'=>['nullable','url','max:255'],'produccion_url'=>['nullable','url','max:255'],'observaciones'=>['nullable','string','max:5000'],
        ]);
    }

    private function phases():array{return ['levantamiento','analisis','diseno','desarrollo','beta','pruebas','ajustes','implementacion','finalizado','mantenimiento'];}
}
