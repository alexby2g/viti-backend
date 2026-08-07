<?php

namespace App\Http\Controllers;

use App\Models\Cuestionario;
use Illuminate\Http\JsonResponse;

class CuestionarioController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Cuestionario::where('activo',true)->withCount('secciones')->get());
    }

    public function show(Cuestionario $cuestionario): JsonResponse
    {
        return response()->json(['data'=>$cuestionario->load('secciones.preguntas')]);
    }
}
