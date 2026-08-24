<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Empresa,Mantenimiento,Proyecto,SolicitudSistema,Suscripcion};
use App\Services\{AppLifecycleService,AgrAssistantService,AgrAutopilotService,AgrMemoryService,AgrProjectConversionService};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        AppLifecycleService $lifecycle,
        AgrAssistantService $assistant,
        AgrMemoryService $memory,
        AgrProjectConversionService $projectConversion,
        AgrAutopilotService $autopilot
    ): JsonResponse {
        if ($request->filled('agr')) {
            $agrInput = (string) $request->query('agr');
            $conversion = $projectConversion->handle($agrInput);
            if ($conversion !== null) return response()->json($conversion);
            return response()->json($memory->handle($agrInput, $assistant));
        }

        $apps = Aplicacion::query()->with(['empresa:id,nombre_comercial','proyecto:id,codigo,nombre,progreso','suscripcion'])->latest()->get();
        $cycles = $apps->map(fn(Aplicacion $app)=>['app'=>$app,'ciclo'=>$lifecycle->status($app)]);
        $attention = $cycles->filter(fn($row)=>in_array($row['ciclo']['estado'],['lista_entrega','gracia','suspendida'],true))->take(8)->values();

        $agrSnapshot = $request->boolean('agr_autopilot')
            ? $autopilot->run()
            : $autopilot->latest();

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
            'agr_autopilot'=>$agrSnapshot,
            'requieren_atencion'=>$attention,
            'solicitudes_recientes'=>SolicitudSistema::with(['empresa:id,nombre_comercial','cliente:id,nombre'])->latest()->limit(5)->get(),
            'proyectos_recientes'=>Proyecto::with(['empresa:id,nombre_comercial','cliente:id,nombre'])->latest()->limit(5)->get(),
        ]);
    }
}
