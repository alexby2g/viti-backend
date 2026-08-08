<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Cliente,Empresa,Mantenimiento,Proyecto,SolicitudSistema};
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $entregasPendientes = Aplicacion::query()
            ->with(['empresa:id,nombre_comercial','proyecto:id,codigo,nombre,progreso'])
            ->where('estado','activo')
            ->where('acceso_cliente',false)
            ->latest()
            ->limit(5)
            ->get();

        return response()->json([
            'resumen'=>[
                'clientes'=>Cliente::count(),
                'empresas'=>Empresa::count(),
                'solicitudes_activas'=>SolicitudSistema::whereNotIn('estado',['rechazada','cerrada'])->count(),
                'proyectos_activos'=>Proyecto::where('estado','activo')->count(),
                'aplicaciones_pendientes_entrega'=>Aplicacion::where('estado','activo')->where('acceso_cliente',false)->count(),
                'mantenimientos_abiertos'=>Mantenimiento::whereNotIn('estado',['resuelto','cerrado'])->count(),
            ],
            'solicitudes_recientes'=>SolicitudSistema::with(['empresa:id,nombre_comercial','cliente:id,nombre'])->latest()->limit(5)->get(),
            'proyectos_recientes'=>Proyecto::with(['empresa:id,nombre_comercial','cliente:id,nombre'])->latest()->limit(5)->get(),
            'entregas_pendientes'=>$entregasPendientes,
        ]);
    }
}
