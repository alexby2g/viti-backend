<?php

namespace App\Http\Controllers;

use App\Models\{Empresa,PlanViti,Usuario};
use App\Support\{Audit,Code};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EmpresaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query=Empresa::with(['cliente:id,nombre,telefono','planViti','usuarios:id,nombre,apellido,usuario,rol,estado'])
            ->withCount(['solicitudes','proyectos','aplicaciones'])->latest('id');
        // Una empresa creada por una solicitud pública todavía es un prospecto en revisión.
        // No ensucia la lista de clientes hasta que VITI complete la revisión.
        if (!$request->boolean('incluir_pendientes')) $query->where('estado','!=','pendiente_revision');
        if($request->filled('buscar')){$term='%'.$request->string('buscar').'%';$query->where(fn($q)=>$q->where('nombre_comercial','like',$term)->orWhere('telefono','like',$term)->orWhere('actividad','like',$term));}
        return response()->json($query->paginate(min(max((int)$request->input('per_page',20),1),100)));
    }

    public function store(Request $request): JsonResponse
    {
        $data=$this->validateData($request);
        $planId=PlanViti::where('codigo','personalizado')->value('id');
        $empresa=Empresa::create($data+['codigo'=>Code::next('empresas','EMP'),'plan_viti_id'=>$planId]);
        Audit::log($request,'empresa_creada',$empresa,'Se registró una empresa cliente.');
        return response()->json(['data'=>$empresa->load(['cliente','planViti','usuarios'])],201);
    }

    public function show(Empresa $empresa): JsonResponse
    {
        return response()->json(['data'=>$empresa->load(['cliente','planViti','usuarios','solicitudes','proyectos','aplicaciones','archivos'])]);
    }

    public function update(Request $request, Empresa $empresa): JsonResponse
    {
        $data=$this->validateData($request,$empresa);
        $empresa->update($data);

        if ($request->has('usuario_id')) {
            $usuarioId=$request->input('usuario_id');
            if ($usuarioId === null || $usuarioId === '') {
                $this->removeOwner($request,$empresa);
            } else {
                $this->assignUserToEmpresa($request,$empresa,(int)$usuarioId,'propietario',$request->input('activo',true),$request->input('permisos'));
            }
        }

        Audit::log($request,'empresa_actualizada',$empresa,'Se actualizaron los datos de la empresa.');
        return response()->json(['data'=>$empresa->fresh()->load(['cliente','planViti','usuarios'])]);
    }

    public function assignUser(Request $request, Empresa $empresa): JsonResponse
    {
        $data=$request->validate([
            'usuario_id'=>['required','integer','exists:usuarios,id'],
            'rol_negocio'=>['required',Rule::in(['propietario','administrador','soporte','empleado'])],
            'activo'=>['nullable','boolean'],
            'permisos'=>['nullable','array'],
        ]);

        $this->assignUserToEmpresa($request,$empresa,(int)$data['usuario_id'],$data['rol_negocio'],$data['activo'] ?? true,$data['permisos'] ?? null);
        return response()->json(['data'=>$empresa->fresh()->load(['cliente','planViti','usuarios'])]);
    }

    public function unassignUser(Request $request, Empresa $empresa, Usuario $usuario): JsonResponse
    {
        $empresa->usuarios()->detach($usuario->id);
        Audit::log($request,'usuario_desasignado_empresa',$empresa,'Se retiró un usuario de la empresa.',['usuario_id'=>$usuario->id]);
        return response()->json(['data'=>$empresa->fresh()->load(['usuarios'])]);
    }

    private function assignUserToEmpresa(Request $request, Empresa $empresa, int $usuarioId, string $rolNegocio, bool $activo=true, ?array $permisos=null): void
    {
        $usuario=Usuario::findOrFail($usuarioId);
        abort_if($usuario->isSuperAdmin(),422,'El superadministrador no se asigna como usuario de un negocio.');
        abort_if($usuario->estado !== 'activo',422,'Solo se puede asignar un usuario activo.');
        abort_unless(in_array($rolNegocio,['propietario','administrador','soporte','empleado'],true),422,'El rol del negocio no es válido.');

        if ($rolNegocio === 'propietario') {
            abort_unless($usuario->rol === 'cliente' && $usuario->cliente_id,422,'El propietario del negocio debe ser una cuenta de cliente VITI.');

            if ($empresa->cliente_id && (int)$empresa->cliente_id !== (int)$usuario->cliente_id) {
                abort(422,'El propietario seleccionado pertenece a otro cliente VITI.');
            }

            if (!$empresa->cliente_id) {
                $empresa->update(['cliente_id' => $usuario->cliente_id]);
            }

            $empresa->usuarios()->wherePivot('rol_negocio','propietario')->get()->each(function(Usuario $actual) use ($empresa): void {
                $empresa->usuarios()->updateExistingPivot($actual->id,['activo'=>false]);
            });
        }

        $empresa->usuarios()->syncWithoutDetaching([
            $usuario->id=>[
                'rol_negocio'=>$rolNegocio,
                'permisos'=>$permisos !== null ? json_encode($permisos) : null,
                'activo'=>$activo,
            ],
        ]);

        Audit::log($request,'usuario_asignado_empresa',$empresa,'Se asignó el usuario '.$usuario->usuario.' a la empresa como '.$rolNegocio.'.',['usuario_id'=>$usuario->id,'rol_negocio'=>$rolNegocio]);
    }

    private function removeOwner(Request $request, Empresa $empresa): void
    {
        $owner = $empresa->usuarios()->wherePivot('rol_negocio','propietario')->get();
        foreach ($owner as $usuario) {
            $empresa->usuarios()->detach($usuario->id);
            Audit::log($request,'propietario_desasignado_empresa',$empresa,'Se retiró el propietario de la empresa sin afectar a los demás usuarios.',['usuario_id'=>$usuario->id]);
        }
    }

    public function destroy(Request $request, Empresa $empresa): JsonResponse
    {
        abort_if($empresa->solicitudes()->exists()||$empresa->proyectos()->exists(),422,'No se puede eliminar una empresa con solicitudes o proyectos.');
        if ($empresa->logo_path) Storage::disk('public')->delete($empresa->logo_path);
        $empresa->delete(); Audit::log($request,'empresa_eliminada',$empresa,'Empresa enviada a papelera.');
        return response()->json(status:204);
    }

    public function uploadLogo(Request $request, Empresa $empresa): JsonResponse
    {
        $request->validate(['logo'=>['required','image','mimes:jpg,jpeg,png,webp','max:3072']], [
            'logo.required'=>'Selecciona un logotipo.','logo.image'=>'El archivo debe ser una imagen.','logo.max'=>'El logotipo no puede superar 3 MB.',
        ]);
        if ($empresa->logo_path) Storage::disk('public')->delete($empresa->logo_path);
        $path=$request->file('logo')->store('empresas/logos','public');
        $empresa->update(['logo_path'=>$path]);
        Audit::log($request,'logo_empresa_actualizado',$empresa,'Se actualizó el logotipo de la empresa.');
        return response()->json(['data'=>$empresa->fresh(),'logo_url'=>Storage::disk('public')->url($path)]);
    }

    private function validateData(Request $request, ?Empresa $empresa=null): array
    {
        return $request->validate([
            'cliente_id'=>['nullable','integer','exists:clientes,id'],'nombre_comercial'=>['required','string','max:180'],'razon_social'=>['nullable','string','max:200'],
            'actividad'=>['nullable','string','max:200'],'telefono'=>['nullable','string','max:30'],'whatsapp'=>['nullable','string','max:30'],'ciudad'=>['nullable','string','max:100'],
            'direccion'=>['nullable','string','max:255'],'observaciones'=>['nullable','string','max:3000'],'estado'=>['nullable',Rule::in(['prospecto','levantamiento','desarrollo','activo','inactivo','pendiente_revision'])],
            'moneda'=>['nullable','string','size:3'],'zona_horaria'=>['nullable','string','max:80'],
        ]);
    }
}
