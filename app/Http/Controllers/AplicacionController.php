<?php

namespace App\Http\Controllers;

use App\Models\{AlertaSaas,Aplicacion,Empresa,Proyecto,Usuario};
use App\Services\{FeatureGateService,TenantContext};
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

        if($r->filled('buscar')){
            $t='%'.$r->string('buscar').'%';
            $q->where(fn($x)=>$x->where('nombre','like',$t)->orWhereHas('empresa',fn($e)=>$e->where('nombre_comercial','like',$t)));
        }

        return response()->json($q->paginate(20));
    }

    public function store(Request $r, FeatureGateService $features, TenantContext $tenants): JsonResponse
    {
        $d=$this->data($r);
        $cloneFromId=$r->integer('clone_from_id');
        $base=Str::slug($d['nombre']);
        $slug=$base.'-'.Str::lower(Str::random(5));

        $a=DB::transaction(function() use($d,$slug,$cloneFromId,$features,$r,$tenants): Aplicacion {
            $empresa=Empresa::query()->with('planViti')->lockForUpdate()->findOrFail($d['empresa_id']);
            $tenants->assertCanManage($r->user(),$empresa);
            $features->assertAppLimit($empresa);

            if($cloneFromId){
                $source=Aplicacion::query()->findOrFail($cloneFromId);
                $sourceEmpresa=$source->empresa;
                abort_unless($sourceEmpresa && (int)$sourceEmpresa->id === (int)$empresa->id,403,'No puedes clonar una aplicación de otra empresa.');
                $row=$source->only([
                    'catalogo_aplicacion_id','descripcion','icono','color_primario','color_secundario','modulos','configuracion',
                    'version','tipo','tecnologias','proveedor_hosting','notas','repositorio_url','url_administracion'
                ]);
                $row=array_merge($row,$d);
                $row['slug']=$slug;
                $row['aplicacion_origen_id']=$source->id;
                $row['es_plantilla']=false;
                $row['entorno']=$d['entorno'] ?? 'beta';
                $row['estado']=$d['estado'] ?? 'en_pruebas';
                $row['acceso_cliente']=false;
                $row['entregado_at']=null;
                $row['provisionado_at']=null;
                $row['publicado_at']=null;
                $row['url']=null;
                return Aplicacion::create($row);
            }

            if(!empty($d['proyecto_id'])){
                $proyecto=Proyecto::query()->lockForUpdate()->findOrFail($d['proyecto_id']);
                abort_unless((int)$proyecto->empresa_id === (int)$empresa->id,422,'El proyecto seleccionado pertenece a otra empresa.');
                abort_if($proyecto->estado === 'cancelado',422,'No se puede integrar una aplicación desde un proyecto cancelado.');
            }

            return Aplicacion::create($d+['slug'=>$slug]);
        });

        Audit::log($r,$cloneFromId?'aplicacion_clonada':'aplicacion_integrada',$a,$cloneFromId?'Se clonó una aplicación desde AppHub.':'Se integró una aplicación a VITI.');
        return response()->json(['data'=>$a->fresh()->load(['empresa','catalogo','origen'])],201);
    }

    public function show(Aplicacion $aplicacion, TenantContext $tenants, Request $r): JsonResponse
    {
        $tenants->assertCanManage($r->user(),$aplicacion->empresa);
        return response()->json(['data'=>$aplicacion->load([
            'empresa','proyecto','catalogo','suscripcion','mantenimientos','archivos','origen',
            'usuarios:id,nombre,apellido,usuario,correo'
        ])]);
    }

    public function update(Request $r,Aplicacion $aplicacion,TenantContext $tenants): JsonResponse
    {
        $empresa=$aplicacion->empresa;
        abort_unless($empresa,404,'La empresa de la aplicación no existe.');
        $tenants->assertCanManage($r->user(),$empresa);

        if($r->boolean('integrar_usuario')){
            $data=$r->validate([
                'user_id'=>['required','integer','exists:usuarios,id'],
                'role'=>['nullable','string','max:50'],
                'activo'=>['sometimes','boolean'],
                'permisos'=>['nullable','array'],
            ]);

            $user=Usuario::findOrFail($data['user_id']);
            abort_unless(
                $aplicacion->empresa->usuarios()->whereKey($user->id)->wherePivot('activo',true)->exists(),
                422,
                'El usuario debe pertenecer primero a la empresa de esta aplicación.'
            );

            $aplicacion->usuarios()->syncWithoutDetaching([
                $user->id=>[
                    'rol'=>$data['role'] ?? 'consulta',
                    'permisos'=>isset($data['permisos']) ? json_encode($data['permisos']) : null,
                    'activo'=>$data['activo'] ?? true,
                ]
            ]);

            Audit::log($r,'usuario_integrado_aplicacion',$aplicacion,'Se integró un usuario a una aplicación desde AppHub.');
            return response()->json(['message'=>'Usuario integrado correctamente.','data'=>$aplicacion->fresh()->load('usuarios:id,nombre,apellido,usuario,correo')]);
        }

        $data=$this->data($r,$aplicacion);
        unset($data['empresa_id'],$data['proyecto_id'],$data['catalogo_aplicacion_id'],$data['entorno'],$data['estado'],$data['acceso_cliente']);
        $aplicacion->update($data);
        Audit::log($r,'aplicacion_actualizada',$aplicacion,'Se actualizó una aplicación.');
        return response()->json(['data'=>$aplicacion->fresh()->load(['empresa','catalogo','origen'])]);
    }

    public function actualizarCiclo(Request $r,Aplicacion $aplicacion,TenantContext $tenants): JsonResponse
    {
        $tenants->assertCanManage($r->user(),$aplicacion->empresa);
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

    public function entregar(Request $r,Aplicacion $aplicacion,TenantContext $tenants): JsonResponse
    {
        $tenants->assertCanManage($r->user(),$aplicacion->empresa);
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

    public function revocar(Request $r,Aplicacion $aplicacion,TenantContext $tenants): JsonResponse
    {
        $tenants->assertCanManage($r->user(),$aplicacion->empresa);
        if(!$aplicacion->acceso_cliente){
            return response()->json(['message'=>'El acceso de esta aplicación ya estaba revocado.','data'=>$aplicacion->load('empresa')]);
        }
        $aplicacion->update(['acceso_cliente'=>false]);
        Audit::log($r,'aplicacion_acceso_revocado',$aplicacion,'Se revocó el acceso del cliente a la aplicación.');
        return response()->json(['message'=>'Acceso revocado correctamente.','data'=>$aplicacion->fresh()->load('empresa')]);
    }

    public function destroy(Request $r,Aplicacion $aplicacion,TenantContext $tenants): JsonResponse
    {
        $tenants->assertCanManage($r->user(),$aplicacion->empresa);
        abort_if($aplicacion->mantenimientos()->whereNotIn('estado',['resuelto','cerrado'])->exists(),422,'La aplicación tiene mantenimientos abiertos.');
        $aplicacion->delete();Audit::log($r,'aplicacion_eliminada',$aplicacion);return response()->json(status:204);
    }

    private function data(Request $r,?Aplicacion $a=null): array
    {
        return $r->validate([
            'empresa_id'=>'required|integer|exists:empresas,id',
            'proyecto_id'=>['nullable','integer','exists:proyectos,id',Rule::unique('aplicaciones','proyecto_id')->ignore($a?->id)],
            'catalogo_aplicacion_id'=>'nullable|integer|exists:catalogo_aplicaciones,id',
            'nombre'=>'required|string|max:200',
            'descripcion'=>'nullable|string|max:5000',
            'icono'=>'nullable|string|max:1000',
            'color_primario'=>'nullable|string|max:30',
            'color_secundario'=>'nullable|string|max:30',
            'modulos'=>'nullable|array',
            'configuracion'=>'nullable|array',
            'es_plantilla'=>'sometimes|boolean',
            'version'=>'nullable|string|max:40',
            'tipo'=>['nullable',Rule::in(['web','movil','escritorio','hibrido','api','otro'])],
            'tecnologias'=>'nullable|string|max:255',
            'entorno'=>['nullable',Rule::in(['desarrollo','beta','produccion'])],
            'estado'=>['nullable',Rule::in(['en_pruebas','activo','pausado','retirado'])],
            'acceso_cliente'=>'sometimes|boolean',
            'url'=>'nullable|url|max:255',
            'url_administracion'=>'nullable|url|max:255',
            'repositorio_url'=>'nullable|url|max:255',
            'proveedor_hosting'=>'nullable|string|max:100',
            'notas'=>'nullable|string|max:5000',
            'publicado_at'=>'nullable|date'
        ]);
    }
}
