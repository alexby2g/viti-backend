<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Empresa,Mantenimiento,Proyecto,SolicitudSistema,Suscripcion};
use App\Services\AppLifecycleService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(AppLifecycleService $lifecycle): JsonResponse
    {
        $apps = Aplicacion::query()->with(['empresa:id,nombre_comercial','proyecto:id,codigo,nombre,progreso','suscripcion'])->latest()->get();
        $cycles = $apps->map(fn(Aplicacion $app)=>['app'=>$app,'ciclo'=>$lifecycle->status($app)]);

        $attention = $cycles->filter(fn($row)=>in_array($row['ciclo']['estado'],['lista_entrega','gracia','suspendida'],true))
            ->take(8)->values();

        return response()->json([
            'resumen'=>[
                'negocios_activos'=>Empresa::where('estado','activo')->count(),
                'aplicaciones_activas'=>$cycles->where('ciclo.estado','activa')->count(),
                'suscripciones_activas'=>Suscripcion::where('estado','activa')->count(),
                'pagos_vencidos'=>Suscripcion::whereIn('estado',['gracia','suspendida'])->count(),
                'solicitudes_activas'=>SolicitudSistema::whereNotIn('estado',['rechazada','cerrada'])->count(),
                'proyectos_activos'=>Proyecto::where('estado','activo')->count(),
                'mantenimientos_abiertos'=>Mantenimiento::whereNotIn('estado',['resuelto','cerrado'])->count(),
                'aplicaciones_pendientes_entrega'=>$cycles->where('ciclo.estado','lista_entrega')->count(),
            ],
            'requieren_atencion'=>$attention,
            'solicitudes_recientes'=>SolicitudSistema::with(['empresa:id,nombre_comercial','cliente:id,nombre'])->latest()->limit(5)->get(),
            'proyectos_recientes'=>Proyecto::with(['empresa:id,nombre_comercial','cliente:id,nombre'])->latest()->limit(5)->get(),
        ]);
    }
}
