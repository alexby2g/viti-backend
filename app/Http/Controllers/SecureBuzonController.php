<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecureBuzonController extends BuzonController
{
    public function notifications(Request $request): JsonResponse
    {
        if ($request->user()?->rol === 'administrador') {
            return response()->json(['data'=>['no_leidos'=>0,'items'=>[]]]);
        }
        return parent::notifications($request);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        if ($request->user()?->rol === 'administrador') {
            return response()->json(['message'=>'No tienes conversaciones privadas VITI asignadas.','data'=>['actualizados'=>0]]);
        }
        return parent::markAllRead($request);
    }
}