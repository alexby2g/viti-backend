<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Proyecto};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientProjectController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $clienteId=(int)$request->user()->cliente_id;
        $proyecto=Proyecto::where('cliente_id',$clienteId)
            ->latest()
            ->with([
                'empresa:id,nombre_comercial',
                'avances'=>fn($q)=>$q->where('visible_cliente',true)->with(['creador:id,nombre,apellido','archivos'])->latest(),
                'aplicacion',
            ])->first();
        return response()->json(['data'=>$proyecto]);
    }
}
