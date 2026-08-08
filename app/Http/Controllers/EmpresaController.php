<?php

namespace App\Http\Controllers;

use App\Models\{Empresa,PlanViti};
use App\Support\{Audit,Code};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EmpresaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query=Empresa::with(['cliente:id,nombre,telefono','planViti'])->withCount(['solicitudes','proyectos','aplicaciones'])->latest('id');
        if($request->filled('buscar')){$term='%'.$request->string('buscar').'%';$query->where(fn($q)=>$q->where('nombre_comercial','like',$term)->orWhere('telefono','like',$term)->orWhere('actividad','like',$term));}
        return response()->json($query->paginate(min(max((int)$request->input('per_page',20),1),100)));
    }

    public function store(Request $request): JsonResponse
    {
        $data=$this->validateData($request);
        $planId=PlanViti::where('codigo','personalizado')->value('id');
        $empresa=Empresa::create($data+['codigo'=>Code::next('empresas','EMP'),'plan_viti_id'=>$planId]);
        Audit::log($request,'empresa_creada',$empresa,'Se registró una empresa cliente.');
        return response()->json(['data'=>$empresa->load(['cliente','planViti'])],201);
    }

    public function show(Empresa $empresa): JsonResponse
    {
        return response()->json(['data'=>$empresa->load(['cliente','planViti','usuarios','solicitudes','proyectos','aplicaciones','archivos'])]);
    }

    public function update(Request $request, Empresa $empresa): JsonResponse
    {
        $empresa->update($this->validateData($request,$empresa));
        Audit::log($request,'empresa_actualizada',$empresa,'Se actualizaron los datos de la empresa.');
        return response()->json(['data'=>$empresa->fresh()->load(['cliente','planViti'])]);
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
