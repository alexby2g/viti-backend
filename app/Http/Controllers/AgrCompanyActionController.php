<?php

namespace App\Http\Controllers;

use App\Models\{Empresa,PlanViti};
use App\Support\{Audit,Code};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgrCompanyActionController extends Controller
{
    public function confirmCreateCompany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cliente_id' => ['nullable','integer','exists:clientes,id'],
            'nombre_comercial' => ['required','string','max:180'],
            'razon_social' => ['nullable','string','max:200'],
            'actividad' => ['nullable','string','max:200'],
            'telefono' => ['nullable','string','max:30'],
            'whatsapp' => ['nullable','string','max:30'],
            'ciudad' => ['nullable','string','max:100'],
            'direccion' => ['nullable','string','max:255'],
        ]);

        $planId = PlanViti::where('codigo','personalizado')->value('id');
        $empresa = Empresa::create($data + [
            'codigo' => Code::next('empresas','EMP'),
            'plan_viti_id' => $planId,
            'estado' => 'activo',
        ]);

        Audit::log($request,'empresa_creada_desde_agr',$empresa,'Se registró una empresa desde AGR Assistant.');

        return response()->json([
            'message' => 'Empresa registrada correctamente desde AGR Assistant.',
            'data' => ['company' => $empresa->only(['id','codigo','nombre_comercial','razon_social','actividad','telefono','whatsapp','ciudad','estado'])],
        ], 201);
    }
}
