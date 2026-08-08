<?php

namespace App\Http\Controllers;

use App\Models\Proyecto;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientProjectController extends Controller
{
    public function show(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        $proyecto = Proyecto::where('empresa_id',$empresa->id)
            ->latest()
            ->with([
                'empresa:id,nombre_comercial',
                'avances'=>fn($q)=>$q->where('visible_cliente',true)->with(['creador:id,nombre,apellido','archivos'])->latest(),
                'aplicacion.catalogo',
            ])->first();
        return response()->json(['data'=>$proyecto]);
    }
}
