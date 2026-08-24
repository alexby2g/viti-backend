<?php

namespace App\Http\Controllers;

use App\Models\Proyecto;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientProjectController
{
    public function show(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanUse($request->user(), $empresa, 'inicio');

        $proyecto = Proyecto::where('empresa_id', $empresa->id)
            ->latest()
            ->with([
                'empresa'=>fn($q)=>$q->select('id','nombre_comercial','plan_viti_id')->with('planViti'),
                'solicitud'=>fn($q)=>$q->select('id','plan_viti_id','presupuesto_estimado','forma_pago_preferida')->with('planViti'),
                'avances'=>fn($q)=>$q->where('visible_cliente',true)->with(['creador:id,nombre,apellido','archivos'])->latest(),
                'aplicacion.catalogo',
            ])->first();

        return response()->json(['data'=>$proyecto]);
    }
}
