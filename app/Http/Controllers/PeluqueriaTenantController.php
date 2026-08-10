<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use Illuminate\Http\JsonResponse;

class PeluqueriaTenantController extends Controller
{
    public function index(): JsonResponse
    {
        $companies = Empresa::query()
            ->select('id','codigo','nombre_comercial','estado')
            ->whereHas('aplicaciones', function ($q): void {
                $q->whereNotIn('estado', ['retirado'])
                    ->whereHas('catalogo', fn ($catalog) => $catalog->where('clave','peluqueria')->where('activo',true));
            })
            ->orderBy('nombre_comercial')
            ->get();

        return response()->json(['data'=>$companies]);
    }
}
