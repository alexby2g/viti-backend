<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Cliente,Empresa,Mantenimiento,Proyecto,SolicitudSistema};
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'resumen'=>[
                'clientes'=>Cliente::count(),
                'empresas'=>Empresa::count(),
                'solicitudes_activas'=>SolicitudSistema::whereNotIn('estado',['rechazada','cerrada'])->count(),
                'proyectos_activos'=>Proyecto::where('estado','activo')->count(),
                'aplicaciones'=>Aplicacion::count(),
                'mantenimientos_abiertos'=>Mantenimiento::whereNotIn('estado',['resuelto','cerrado'])->count(),
            ],
            'solicitudes_recientes'=>SolicitudSistema::with(['empresa:id,nombre_comercial','cliente:id,nombre'])->latest()->limit(5)->get(),
            'proyectos_recientes'=>Proyecto::with(['empresa:id,nombre_comercial','cliente:id,nombre'])->latest()->limit(5)->get(),
        ]);
    }
}
