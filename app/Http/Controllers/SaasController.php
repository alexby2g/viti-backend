<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,CatalogoAplicacion,Empresa,Mantenimiento,PlanViti,Proyecto,SolicitudSistema,Suscripcion,Usuario};
use App\Services\{AppLifecycleService,TenantContext};
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaasController extends Controller
{
    public function overview(AppLifecycleService $lifecycle): JsonResponse
    {
        $apps = Aplicacion::query()->with(['empresa:id,nombre_comercial','suscripcion'])->latest()->limit(100)->get();
        $states = $apps->map(fn(Aplicacion $app) => ['app'=>$app,'ciclo'=>$lifecycle->status($app)]);
        $attention = $states->filter(fn($row) => in_array($row['ciclo']['estado'],['lista_entrega','gracia','suspendida'],true))->take(10)->values();

        return response()->json(['data'=>[
            'resumen'=>[
                'negocios_activos'=>Empresa::where('estado','activo')->count(),
                'aplicaciones_activas'=>$states->where('ciclo.estado','activa')->count(),
                'suscripciones_activas'=>Suscripcion::where('estado','activa')->count(),
                'suscripciones_gracia'=>Suscripcion::where('estado','gracia')->count(),
                'suscripciones_suspendidas'=>Suscripcion::where('estado','suspendida')->count(),
                'solicitudes_activas'=>SolicitudSistema::whereNotIn('estado',['rechazada','cerrada'])->count(),
                'soportes_abiertos'=>Mantenimiento::whereNotIn('estado',['resuelto','cerrado'])->count(),
                'usuarios_negocio'=>Usuario::where('rol','cliente')->where('estado','activo')->count(),
            ],
            'atencion'=>$attention,
            'catalogo'=>CatalogoAplicacion::where('activo',true)->orderBy('orden')->get(),
            'planes'=>PlanViti::orderBy('id')->get(),
        ]]);
    }

    public function catalogo(): JsonResponse
    {
        return response()->json(['data'=>CatalogoAplicacion::query()->withCount('aplicaciones')->orderBy('orden')->get()]);
    }

    public function provisionar(Request $request, CatalogoAplicacion $catalogoAplicacion, TenantContext $tenants): JsonResponse
    {
        abort_unless($catalogoAplicacion->activo,422,'Esta aplicación del catálogo no está activa.');
        $data = $request->validate([
            'empresa_id'=>['required','integer','exists:empresas,id'],
            'proyecto_id'=>['nullable','integer','exists:proyectos,id'],
            'version'=>['nullable','string','max:40'],
            'notas'=>['nullable','string','max:3000'],
        ]);
        $empresa = Empresa::with('planViti')->findOrFail($data['empresa_id']);
        $tenants->assertAppLimit($empresa);
        if (!empty($data['proyecto_id'])) abort_unless(Proyecto::whereKey($data['proyecto_id'])->where('empresa_id',$empresa->id)->exists(),422,'El proyecto no pertenece a este negocio.');
        abort_if(Aplicacion::where('empresa_id',$empresa->id)->where('catalogo_aplicacion_id',$catalogoAplicacion->id)->whereNotIn('estado',['retirado'])->exists(),422,'Este negocio ya tiene una instancia activa de esta aplicación.');

        $app = Aplicacion::create([
            'empresa_id'=>$empresa->id,'proyecto_id'=>$data['proyecto_id'] ?? null,'catalogo_aplicacion_id'=>$catalogoAplicacion->id,
            'nombre'=>$catalogoAplicacion->nombre.' · '.$empresa->nombre_comercial,
            'slug'=>Str::slug($catalogoAplicacion->clave.'-'.$empresa->codigo.'-'.Str::lower(Str::random(4))),
            'version'=>$data['version'] ?? '1.0.0','tipo'=>$catalogoAplicacion->tipo,'entorno'=>'desarrollo','estado'=>'en_pruebas','acceso_cliente'=>false,
            'provisionado_at'=>now(),'notas'=>$data['notas'] ?? null,
        ]);
        Audit::log($request,'app_provisionada',$app,'Se creó una instancia de '.$catalogoAplicacion->nombre.' para '.$empresa->nombre_comercial.'.');
        return response()->json(['data'=>$app->load(['empresa','catalogo','proyecto'])],201);
    }

    public function planes(): JsonResponse { return response()->json(['data'=>PlanViti::orderBy('id')->get()]); }

    public function guardarPlan(Request $request, ?PlanViti $plan = null): JsonResponse
    {
        $data = $request->validate([
            'codigo'=>['required','string','max:60',Rule::unique('planes_viti','codigo')->ignore($plan?->id)],'nombre'=>['required','string','max:100'],
            'descripcion'=>['nullable','string','max:2000'],
            'precio_proyecto'=>['nullable','numeric','min:0','max:9999999999'],
            'precio_mensual'=>['nullable','numeric','min:0','max:9999999999'],
            'dias_prueba'=>['nullable','integer','min:0','max:60'],
            'modulos'=>['nullable','array'],
            'modulos.*'=>['string',Rule::in(['inicio','agenda','ordenes','clientes','equipos','tecnicos','inventario','pagos','garantias','historial','buzon'])],
            'max_usuarios'=>['nullable','integer','min:1','max:10000'],'max_aplicaciones'=>['nullable','integer','min:1','max:10000'],'activo'=>['sometimes','boolean'],
        ]);
        $plan ??= new PlanViti(); $plan->fill($data)->save();
        return response()->json(['data'=>$plan->fresh()],$plan->wasRecentlyCreated?201:200);
    }

    public function asignarPlan(Request $request, Empresa $empresa): JsonResponse
    {
        $data = $request->validate(['plan_viti_id'=>['nullable','integer','exists:planes_viti,id']]);
        $plan = !empty($data['plan_viti_id']) ? PlanViti::findOrFail($data['plan_viti_id']) : null;
        if ($plan?->max_usuarios !== null) {
            $actuales = $empresa->usuarios()->wherePivot('activo',true)->count();
            abort_if($actuales > $plan->max_usuarios,422,"El negocio ya tiene {$actuales} usuarios activos y el plan permite {$plan->max_usuarios}.");
        }
        if ($plan?->max_aplicaciones !== null) {
            $actuales = $empresa->aplicaciones()->whereNotIn('estado',['retirado'])->count();
            abort_if($actuales > $plan->max_aplicaciones,422,"El negocio ya tiene {$actuales} aplicaciones y el plan permite {$plan->max_aplicaciones}.");
        }
        $empresa->update($data);
        Audit::log($request,'plan_viti_asignado',$empresa,'Se actualizó el plan SaaS del negocio.',$data);
        return response()->json(['data'=>$empresa->fresh()->load('planViti')]);
    }
}
