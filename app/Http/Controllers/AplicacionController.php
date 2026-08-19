<?php

namespace App\Http\Controllers;

use App\Models\{AlertaSaas,Aplicacion,Empresa,Proyecto};
use App\Services\FeatureGateService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AplicacionController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $q=Aplicacion::with([
            'empresa:id,nombre_comercial',
            'proyecto:id,nombre,codigo,precio_acordado,estado_pago',
            'catalogo',
            'suscripcion:id,aplicacion_id,estado,prueba_hasta,fecha_vencimiento,dias_gracia',
        ])->latest();
        if($r->filled('buscar')){$t='%'.$r->string('buscar').'%';$q->where(fn($x)=>$x->where('nombre','like',$t)->orWhereHas('empresa',fn($e)=>$e->where('nombre_comercial','like',$t)));}
        return response()->json($q->paginate(20));
    }

    public function store(Request $r, FeatureGateService $features): JsonResponse
    {
        $d=$this->data($r);$base=Str::slug($d['nombre']);$slug=$base.'-'.Str::lower(Str::random(5));
        $a=DB::transaction(function() use($d,$slug,$features): Aplicacion {
            $empresa=Empresa::query()->with('planViti')->lockForUpdate()->findOrFail($d['empresa_id']);
            $features->assertAppLimit($empresa);

            if(!empty($d['proyecto_id'])){
                $proyecto=Proyecto::query()->lockForUpdate()->findOrFail($d['proyecto_id']);
                abort_unless((int)$proyecto->empresa_id === (int)$empresa->id,422,'El proyecto seleccionado pertenece a otra empresa.');
                abort_if($proyecto->estado === 'cancelado',422,'No se puede integrar una aplicación desde un proyecto cancelado.');
            }

            return Aplicacion::create($d+['slug'=>$slug]);
        });
        Audit::log($r,'aplicacion_integrada',$a,'Se integró una aplicación a VITI.');
        return response()->json(['data'=>$a->load('empresa')],201);
    }

    public function show(Aplicacion $aplicacion, FeatureGateService $features): JsonResponse
    {
        $aplicacion->load(['empresa.planViti','proyecto','catalogo','suscripcion','mantenimientos','archivos']);
        if($this->isEditorRequest(request())){
            return response()->json(['data'=>$this->editorPayload($aplicacion,$features)]);
        }
        return response()->json(['data'=>$aplicacion]);
    }

    public function update(Request $r,Aplicacion $aplicacion, FeatureGateService $features): JsonResponse
    {
        if($this->isEditorRequest($r)){
            return $this->updateEditor($r,$aplicacion,$features);
        }

        $data=$this->data($r,$aplicacion);
        unset($data['empresa_id'],$data['proyecto_id'],$data['catalogo_aplicacion_id'],$data['entorno'],$data['estado'],$data['acceso_cliente']);
        $aplicacion->update($data);
        Audit::log($r,'aplicacion_actualizada',$aplicacion,'Se actualizó una aplicación.');
        return response()->json(['data'=>$aplicacion->fresh()->load('empresa')]);
    }

    public function actualizarCiclo(Request $r,Aplicacion $aplicacion): JsonResponse
    {
        $data=$r->validate([
            'entorno'=>['required',Rule::in(['desarrollo','beta','produccion'])],
            'estado'=>['required',Rule::in(['en_pruebas','activo','pausado','retirado'])],
        ]);

        abort_if($aplicacion->estado==='retirado' && $data['estado']!=='retirado',422,'Una aplicación retirada no puede reactivarse desde el ciclo normal. Crea una nueva versión o aplicación si debe volver a operar.');

        if($aplicacion->entregado_at){
            abort_unless($data['entorno']==='produccion',422,'Una aplicación ya entregada debe mantenerse en producción. Revoca el acceso si necesitas intervenirla.');
            abort_if($data['estado']==='en_pruebas',422,'Una aplicación ya entregada no puede volver al estado en pruebas mediante una edición normal.');
        }

        if($aplicacion->entorno===$data['entorno'] && $aplicacion->estado===$data['estado']){
            return response()->json(['message'=>'La aplicación ya tiene ese ciclo técnico y operativo.','data'=>$aplicacion->load(['empresa','suscripcion'])]);
        }

        $aplicacion->update($data);
        Audit::log($r,'aplicacion_ciclo_actualizado',$aplicacion,'Se actualizó manualmente el ciclo técnico y operativo de la aplicación.');

        return response()->json(['message'=>'Estado de la aplicación actualizado.','data'=>$aplicacion->fresh()->load(['empresa','suscripcion'])]);
    }

    public function entregar(Request $r,Aplicacion $aplicacion): JsonResponse
    {
        if($aplicacion->acceso_cliente && $aplicacion->entregado_at){
            return response()->json(['message'=>'La aplicación ya estaba entregada.','data'=>$aplicacion->load('empresa')]);
        }

        abort_unless($aplicacion->estado==='activo'&&$aplicacion->entorno==='produccion',422,'La aplicación debe estar activa y en producción antes de entregarla.');
        $proyecto=$aplicacion->proyecto;
        if($proyecto&&$proyecto->precio_acordado!==null) abort_unless($proyecto->estado_pago==='pagado',422,'El proyecto tiene un saldo pendiente. Registra el pago final antes de entregar la aplicación.');

        $aplicacion->update(['acceso_cliente'=>true,'entregado_at'=>$aplicacion->entregado_at ?: now()]);

        $users=$aplicacion->empresa?->usuarios()->wherePivot('activo',true)->get() ?? collect();
        foreach($users as $user){
            AlertaSaas::firstOrCreate(
                ['usuario_id'=>$user->id,'clave'=>'app_entregada_'.$aplicacion->id],
                ['empresa_id'=>$aplicacion->empresa_id,'aplicacion_id'=>$aplicacion->id,'tipo'=>'entrega','titulo'=>'Tu aplicación ya está disponible','mensaje'=>$aplicacion->nombre.' fue entregada y ya puede abrirse desde Mis aplicaciones.','ruta'=>'/mi-aplicaciones']
            );
        }

        Audit::log($r,'aplicacion_entregada',$aplicacion,'Se habilitó el acceso del cliente a la aplicación.');
        return response()->json(['message'=>'Aplicación entregada correctamente.','data'=>$aplicacion->fresh()->load('empresa')]);
    }

    public function revocar(Request $r,Aplicacion $aplicacion): JsonResponse
    {
        if(!$aplicacion->acceso_cliente){
            return response()->json(['message'=>'El acceso de esta aplicación ya estaba revocado.','data'=>$aplicacion->load('empresa')]);
        }
        $aplicacion->update(['acceso_cliente'=>false]);
        Audit::log($r,'aplicacion_acceso_revocado',$aplicacion,'Se revocó el acceso del cliente a la aplicación.');
        return response()->json(['message'=>'Acceso revocado correctamente.','data'=>$aplicacion->fresh()->load('empresa')]);
    }

    public function destroy(Request $r,Aplicacion $aplicacion): JsonResponse
    {
        abort_if($aplicacion->mantenimientos()->whereNotIn('estado',['resuelto','cerrado'])->exists(),422,'La aplicación tiene mantenimientos abiertos.');
        $aplicacion->delete();Audit::log($r,'aplicacion_eliminada',$aplicacion);return response()->json(status:204);
    }

    private function updateEditor(Request $r, Aplicacion $application, FeatureGateService $features): JsonResponse
    {
        $data=$r->validate([
            'heredar_modulos_plan'=>['required','boolean'],
            'modulos'=>['nullable','array'],
            'modulos.*'=>['string','in:inicio,agenda,ordenes,clientes,equipos,tecnicos,inventario,pagos,garantias,historial,buzon'],
            'branding'=>['nullable','array'],
            'branding.nombre'=>['nullable','string','max:200'],
            'branding.logo_url'=>['nullable','url','max:1000'],
            'branding.icono'=>['nullable','string','max:100'],
            'branding.color_principal'=>['nullable','string','max:30'],
            'branding.color_secundario'=>['nullable','string','max:30'],
        ]);

        $empresa=$application->empresa()->with('planViti')->firstOrFail();
        $planModules=$features->modules($empresa);
        $selected=array_values(array_unique(array_filter(array_map('strval',$data['modulos']??[]))));

        if(!$data['heredar_modulos_plan']){
            abort_unless($planModules===null || count(array_diff($selected,$planModules))===0,422,
                'No puedes habilitar módulos que no pertenecen al plan VITI de la empresa.');
        }

        $current=(array)($application->configuracion??[]);
        $branding=array_merge((array)($current['branding']??[]),(array)($data['branding']??[]));
        $application->update([
            'configuracion'=>[
                ...$current,
                'heredar_modulos_plan'=>(bool)$data['heredar_modulos_plan'],
                'modulos'=>$selected,
                'branding'=>array_filter($branding,fn($value)=>$value!==null&&$value!==''),
                'configurado_at'=>now()->toIso8601String(),
            ],
        ]);

        Audit::log($r,'aplicacion_moldeada',$application,'Se actualizó la configuración independiente de una aplicación: módulos y marca.');
        return response()->json(['message'=>'Configuración de la aplicación guardada.','data'=>$this->editorPayload($application->fresh()->load(['empresa.planViti','catalogo','suscripcion']),$features)]);
    }

    private function editorPayload(Aplicacion $application, FeatureGateService $features): array
    {
        $config=(array)($application->configuracion??[]);
        $inherit=!array_key_exists('heredar_modulos_plan',$config)||$config['heredar_modulos_plan']!==false;
        $planModules=$features->modules($application->empresa);
        return [
            'aplicacion'=>$application,
            'catalogo'=>$application->catalogo,
            'plan'=>$application->empresa->planViti?[ 
                'id'=>$application->empresa->planViti->id,
                'codigo'=>$application->empresa->planViti->codigo,
                'nombre'=>$application->empresa->planViti->nombre,
                'modulos'=>$planModules,
            ]:null,
            'configuracion'=>[
                'heredar_modulos_plan'=>$inherit,
                'modulos'=>$features->modulesForApp($application)??FeatureGateService::MODULES,
                'branding'=>$config['branding']??[],
            ],
            'catalogo_modulos'=>$features->moduleCatalog(),
        ];
    }

    private function isEditorRequest(Request $r): bool
    {
        return $r->boolean('editor') || $r->filled('heredar_modulos_plan') || $r->has('branding');
    }

    private function data(Request $r,?Aplicacion $a=null): array
    {
        return $r->validate([
            'empresa_id'=>'required|integer|exists:empresas,id','proyecto_id'=>['nullable','integer','exists:proyectos,id',Rule::unique('aplicaciones','proyecto_id')->ignore($a?->id)],
            'catalogo_aplicacion_id'=>'nullable|integer|exists:catalogo_aplicaciones,id','nombre'=>'required|string|max:200','version'=>'nullable|string|max:40',
            'tipo'=>['nullable',Rule::in(['web','movil','escritorio','hibrido','api','otro'])],'tecnologias'=>'nullable|string|max:255','entorno'=>['nullable',Rule::in(['desarrollo','beta','produccion'])],
            'estado'=>['nullable',Rule::in(['en_pruebas','activo','pausado','retirado'])],'acceso_cliente'=>'sometimes|boolean','url'=>'nullable|url|max:255','url_administracion'=>'nullable|url|max:255',
            'repositorio_url'=>'nullable|url|max:255','proveedor_hosting'=>'nullable|string|max:100','notas'=>'nullable|string|max:5000','publicado_at'=>'nullable|date'
        ]);
    }
}
