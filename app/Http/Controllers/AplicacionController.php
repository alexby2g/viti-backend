<?php
namespace App\Http\Controllers;
use App\Models\Aplicacion;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
class AplicacionController extends Controller
{
 public function index(Request $r):JsonResponse{$q=Aplicacion::with(['empresa:id,nombre_comercial','proyecto:id,nombre,codigo,precio_acordado,estado_pago'])->latest();if($r->filled('buscar')){$t='%'.$r->string('buscar').'%';$q->where(fn($x)=>$x->where('nombre','like',$t)->orWhereHas('empresa',fn($e)=>$e->where('nombre_comercial','like',$t)));}return response()->json($q->paginate(20));}
 public function store(Request $r):JsonResponse{$d=$this->data($r);$base=Str::slug($d['nombre']);$slug=$base.'-'.Str::lower(Str::random(5));$a=Aplicacion::create($d+['slug'=>$slug]);Audit::log($r,'aplicacion_integrada',$a,'Se integró una aplicación a VITI.');return response()->json(['data'=>$a->load('empresa')],201);}
 public function show(Aplicacion $aplicacion):JsonResponse{return response()->json(['data'=>$aplicacion->load(['empresa','proyecto','suscripcion','mantenimientos','archivos'])]);}
 public function update(Request $r,Aplicacion $aplicacion):JsonResponse{$aplicacion->update($this->data($r,$aplicacion));Audit::log($r,'aplicacion_actualizada',$aplicacion,'Se actualizó una aplicación.');return response()->json(['data'=>$aplicacion->fresh()->load('empresa')]);}
 public function entregar(Request $r,Aplicacion $aplicacion):JsonResponse{abort_unless($aplicacion->estado==='activo'&&$aplicacion->entorno==='produccion',422,'La aplicación debe estar activa y en producción antes de entregarla.');$proyecto=$aplicacion->proyecto;if($proyecto&&$proyecto->precio_acordado!==null){abort_unless($proyecto->estado_pago==='pagado',422,'El proyecto tiene un saldo pendiente. Registra el pago final antes de entregar la aplicación.');}$aplicacion->update(['acceso_cliente'=>true,'entregado_at'=>now()]);Audit::log($r,'aplicacion_entregada',$aplicacion,'Se habilitó el acceso del cliente a la aplicación.');return response()->json(['data'=>$aplicacion->fresh()->load('empresa')]);}
 public function revocar(Request $r,Aplicacion $aplicacion):JsonResponse{$aplicacion->update(['acceso_cliente'=>false]);Audit::log($r,'aplicacion_acceso_revocado',$aplicacion,'Se revocó el acceso del cliente a la aplicación.');return response()->json(['data'=>$aplicacion->fresh()->load('empresa')]);}
 public function destroy(Request $r,Aplicacion $aplicacion):JsonResponse{abort_if($aplicacion->mantenimientos()->whereNotIn('estado',['resuelto','cerrado'])->exists(),422,'La aplicación tiene mantenimientos abiertos.');$aplicacion->delete();Audit::log($r,'aplicacion_eliminada',$aplicacion);return response()->json(status:204);}
 private function data(Request $r,?Aplicacion $a=null):array{return $r->validate(['empresa_id'=>'required|integer|exists:empresas,id','proyecto_id'=>['nullable','integer','exists:proyectos,id',Rule::unique('aplicaciones','proyecto_id')->ignore($a?->id)],'nombre'=>'required|string|max:200','version'=>'nullable|string|max:40','tipo'=>['nullable',Rule::in(['web','movil','escritorio','hibrido','api','otro'])],'tecnologias'=>'nullable|string|max:255','entorno'=>['nullable',Rule::in(['desarrollo','beta','produccion'])],'estado'=>['nullable',Rule::in(['en_pruebas','activo','pausado','retirado'])],'acceso_cliente'=>'sometimes|boolean','url'=>'nullable|url|max:255','url_administracion'=>'nullable|url|max:255','repositorio_url'=>'nullable|url|max:255','proveedor_hosting'=>'nullable|string|max:100','notas'=>'nullable|string|max:5000','publicado_at'=>'nullable|date']);}
}
