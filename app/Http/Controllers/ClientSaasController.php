<?php

namespace App\Http\Controllers;

use App\Models\{CatalogoAplicacion,Empresa,Usuario};
use App\Services\TenantContext;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Throwable;

class ClientSaasController extends Controller
{
    private const PAYMENT_METHODS = ['qr','transferencia','efectivo','otro'];

    public function negocios(Request $request): JsonResponse
    {
        $items = $request->user()->negocios()
            ->wherePivot('activo',true)
            ->with('planViti')
            ->withCount('aplicaciones')
            ->orderBy('nombre_comercial')
            ->get()
            ->map(fn(Empresa $e) => [
                'id'=>$e->id,'codigo'=>$e->codigo,'nombre_comercial'=>$e->nombre_comercial,'actividad'=>$e->actividad,
                'logo_url'=>$e->logo_url,'moneda'=>$e->moneda,'metodo_pago_preferido'=>$e->metodo_pago_preferido ?: 'qr','zona_horaria'=>$e->zona_horaria,
                'rol'=>$e->pivot?->rol_negocio,'aplicaciones_count'=>$e->aplicaciones_count,'plan'=>$e->planViti,
            ]);
        return response()->json(['data'=>$items]);
    }

    public function negocio(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        return response()->json(['data'=>[
            'empresa'=>$empresa->load('planViti'),
            'rol'=>$tenants->role($request->user(),$empresa),
            'puede_administrar'=>$tenants->canManage($request->user(),$empresa),
        ]]);
    }

    public function actualizarNegocio(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        $data = $request->validate([
            'nombre_comercial'=>['required','string','max:180'],'razon_social'=>['nullable','string','max:200'],'actividad'=>['nullable','string','max:200'],
            'telefono'=>['nullable','string','max:30'],'whatsapp'=>['nullable','string','max:30'],'ciudad'=>['nullable','string','max:100'],'direccion'=>['nullable','string','max:255'],
            'moneda'=>['required','string','size:3'],'metodo_pago_preferido'=>['sometimes',Rule::in(self::PAYMENT_METHODS)],'zona_horaria'=>['required','string','max:80'],
        ]);
        $empresa->update($data);
        Audit::log($request,'negocio_actualizado',$empresa,'El cliente actualizó la configuración de su negocio.');
        return response()->json(['data'=>$empresa->fresh()->load('planViti')]);
    }

    public function logo(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        $request->validate(['logo'=>['required','image','mimes:jpg,jpeg,png,webp','max:3072']]);
        $disk = Storage::disk('public'); $old = $empresa->logo_path;
        try {
            $path = $request->file('logo')->store('empresas/logos','public');
            if (!$path) throw new \RuntimeException('No se pudo guardar el logotipo.');
            $empresa->update(['logo_path'=>$path]);
            if ($old && $old !== $path) { try { $disk->delete($old); } catch (Throwable) {} }
            return response()->json(['data'=>$empresa->fresh(),'logo_url'=>$empresa->fresh()->logo_url]);
        } catch (Throwable $e) {
            report($e); return response()->json(['message'=>'No pudimos guardar el logotipo en el almacenamiento permanente.'],503);
        }
    }

    public function equipo(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        $items = $empresa->usuarios()->orderBy('nombre')->get()->map(fn(Usuario $u) => [
            'id'=>$u->id,'nombre'=>$u->nombre,'apellido'=>$u->apellido,'usuario'=>$u->usuario,'telefono'=>$u->telefono,'documento'=>$u->documento,
            'estado'=>$u->estado,'rol_negocio'=>$u->pivot?->rol_negocio,'permisos'=>$this->permissions($u->pivot?->permisos),'activo'=>(bool)$u->pivot?->activo,
            'metodo_pago_negocio'=>$empresa->metodo_pago_preferido ?: 'qr',
        ]);
        return response()->json(['data'=>$items]);
    }

    public function agregarUsuario(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        $tenants->assertUserLimit($empresa);
        $request->merge(['usuario'=>Str::lower(trim((string)$request->input('usuario'))),'documento'=>preg_replace('/\D+/','',(string)$request->input('documento'))]);
        $data = $request->validate([
            'nombre'=>['required','string','max:100'],'apellido'=>['nullable','string','max:100'],
            'usuario'=>['required','string','alpha_dash','min:4','max:80','not_regex:/^\d+$/','unique:usuarios,usuario'],'telefono'=>['nullable','string','max:30','unique:usuarios,telefono'],
            'documento'=>['required','regex:/^[0-9]{5,15}$/','unique:usuarios,documento'],
            'password'=>['required','confirmed',Password::min(8)->letters()->numbers()],'rol_negocio'=>['required',Rule::in(['propietario','administrador','empleado'])],
            'permisos'=>['nullable','array'],'permisos.*'=>['string',Rule::in($tenants->modules($empresa) ?? ['inicio','agenda','ordenes','clientes','equipos','tecnicos','inventario','pagos','garantias','historial','buzon'])],
        ]);
        $role = $data['rol_negocio'];
        abort_if($role==='propietario' && $tenants->role($request->user(),$empresa)!=='propietario',403,'Solo un propietario puede crear a otro propietario.');
        $defaults=['inicio','agenda','ordenes'];
        $allowed=$tenants->modules($empresa);
        $permissions=$role==='empleado'?array_values(array_unique($data['permisos']??($allowed===null?$defaults:array_intersect($defaults,$allowed)))):null;
        unset($data['rol_negocio'],$data['permisos'],$data['password_confirmation']);
        $usuario = Usuario::create($data + ['cliente_id'=>null,'rol'=>'cliente','estado'=>'activo']);
        $empresa->usuarios()->attach($usuario->id,['rol_negocio'=>$role,'permisos'=>$permissions?json_encode($permissions):null,'activo'=>true]);
        Audit::log($request,'usuario_negocio_creado',$empresa,'Se agregó '.$usuario->usuario.' al negocio.',['usuario_id'=>$usuario->id,'rol'=>$role]);
        return response()->json(['data'=>$usuario->fresh()],201);
    }

    public function actualizarUsuario(Request $request, Usuario $usuario, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        $membership = $empresa->usuarios()->where('usuarios.id',$usuario->id)->first();
        abort_unless($membership,404,'Ese usuario no pertenece a este negocio.');
        $data = $request->validate(['rol_negocio'=>['required',Rule::in(['propietario','administrador','empleado'])],'activo'=>['required','boolean'],'password'=>['nullable','confirmed',Password::min(8)->letters()->numbers()],'permisos'=>['nullable','array'],'permisos.*'=>['string',Rule::in($tenants->modules($empresa) ?? ['inicio','agenda','ordenes','clientes','equipos','tecnicos','inventario','pagos','garantias','historial','buzon'])]]);
        abort_if($data['rol_negocio']==='propietario' && $tenants->role($request->user(),$empresa)!=='propietario',403,'Solo un propietario puede asignar ese rol.');
        $owners = $empresa->usuarios()->wherePivot('activo',true)->wherePivot('rol_negocio','propietario')->count();
        if ($membership->pivot?->rol_negocio === 'propietario' && ($data['rol_negocio'] !== 'propietario' || !$data['activo'])) abort_if($owners <= 1,422,'El negocio debe conservar al menos un propietario activo.');
        $defaults=['inicio','agenda','ordenes'];
        $allowed=$tenants->modules($empresa);
        $permissions=$data['rol_negocio']==='empleado'?array_values(array_unique($data['permisos']??($allowed===null?$defaults:array_intersect($defaults,$allowed)))):null;
        $empresa->usuarios()->updateExistingPivot($usuario->id,['rol_negocio'=>$data['rol_negocio'],'permisos'=>$permissions?json_encode($permissions):null,'activo'=>$data['activo']]);
        if (!empty($data['password'])) $usuario->update(['password'=>$data['password']]);
        return response()->json(['message'=>'Acceso actualizado.']);
    }

    public function quitarUsuario(Request $request, Usuario $usuario, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        abort_if((int)$request->user()->id === (int)$usuario->id,422,'No puedes quitar tu propio acceso al negocio.');
        $membership = $empresa->usuarios()->where('usuarios.id',$usuario->id)->first();
        abort_unless($membership,404,'Ese usuario no pertenece a este negocio.');
        if ($membership->pivot?->rol_negocio === 'propietario') {
            $owners = $empresa->usuarios()->wherePivot('activo',true)->wherePivot('rol_negocio','propietario')->count();
            abort_if($owners <= 1,422,'El negocio debe conservar al menos un propietario activo.');
        }
        $empresa->usuarios()->updateExistingPivot($usuario->id,['activo'=>false]);
        return response()->json(status:204);
    }

    public function catalogo(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $installed = $empresa->aplicaciones()->whereNotIn('estado',['retirado'])->pluck('catalogo_aplicacion_id')->filter()->all();
        $items = CatalogoAplicacion::where('activo',true)->where('solicitable',true)->orderBy('orden')->get()->map(fn(CatalogoAplicacion $app) => [
            'id'=>$app->id,'clave'=>$app->clave,'nombre'=>$app->nombre,'descripcion'=>$app->descripcion,'icono'=>$app->icono,
            'instalada'=>in_array($app->id,$installed,true),'solicitable'=>$app->solicitable,
        ]);
        return response()->json(['data'=>$items]);
    }

    private function permissions(mixed $value): array
    {
        if (is_string($value)) $value=json_decode($value,true);
        return is_array($value)?array_values($value):[];
    }
}
