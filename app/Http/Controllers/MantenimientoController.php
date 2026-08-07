<?php
namespace App\Http\Controllers;
use App\Models\Mantenimiento;
use App\Support\{Audit,Code};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class MantenimientoController extends Controller
{
 public function index(Request $r):JsonResponse{$q=Mantenimiento::with(['aplicacion:id,nombre','empresa:id,nombre_comercial','cliente:id,nombre','asignado:id,nombre,apellido'])->latest();if($r->filled('estado'))$q->where('estado',$r->string('estado'));return response()->json($q->paginate(20));}
 public function store(Request $r):JsonResponse{$d=$this->data($r);$m=Mantenimiento::create($d+['codigo'=>Code::next('mantenimientos','MNT')]);Audit::log($r,'mantenimiento_creado',$m,'Se registró una solicitud de mantenimiento.');return response()->json(['data'=>$m],201);}
 public function update(Request $r,Mantenimiento $mantenimiento):JsonResponse{$mantenimiento->update($this->data($r,$mantenimiento));Audit::log($r,'mantenimiento_actualizado',$mantenimiento);return response()->json(['data'=>$mantenimiento]);}
 public function destroy(Request $r,Mantenimiento $mantenimiento):JsonResponse{$mantenimiento->delete();Audit::log($r,'mantenimiento_eliminado',$mantenimiento);return response()->json(status:204);}
 private function data(Request $r,?Mantenimiento $m=null):array{return $r->validate(['aplicacion_id'=>'required|integer|exists:aplicaciones,id','empresa_id'=>'required|integer|exists:empresas,id','cliente_id'=>'nullable|integer|exists:clientes,id','asignado_a'=>'nullable|integer|exists:usuarios,id','titulo'=>'required|string|max:200','descripcion'=>'required|string|max:5000','tipo'=>['nullable',Rule::in(['soporte','error','mejora','actualizacion','capacitacion'])],'prioridad'=>['nullable',Rule::in(['baja','normal','alta','urgente'])],'estado'=>['nullable',Rule::in(['abierto','en_proceso','en_espera','resuelto','cerrado'])]]);}
}
