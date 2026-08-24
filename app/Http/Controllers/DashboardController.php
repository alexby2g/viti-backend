<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Empresa,Mantenimiento,Proyecto,SolicitudSistema,Suscripcion};
use App\Services\{AppLifecycleService,AgrActivityService,AgrAssistantService,AgrAutopilotService,AgrMemoryService,AgrPermissionService,AgrProjectConversionService};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        AppLifecycleService $lifecycle,
        AgrActivityService $activity,
        AgrAssistantService $assistant,
        AgrMemoryService $memory,
        AgrProjectConversionService $projectConversion,
        AgrAutopilotService $autopilot,
        AgrPermissionService $permissions
    ): JsonResponse {
        if ($request->filled('agr')) {
            $agrInput = (string) $request->query('agr');
            $conversion = $projectConversion->handle($agrInput);
            if ($conversion !== null) return response()->json($conversion);
            return response()->json($memory->handle($agrInput, $assistant));
        }

        if ($request->boolean('agr_autopilot')) {
            $snapshot = $autopilot->run($permissions);
            $activity->record('autopilot_review', 'AGR revisó VITI', $snapshot['message'], [
                'health' => $snapshot['health'],
                'permissions' => $snapshot['permissions'],
                'priorities' => count($snapshot['priorities']),
                'workflow_recommendations' => count($snapshot['workflow_recommendations']),
                'metrics' => $snapshot['metrics'],
            ]);
            foreach ($snapshot['priorities'] as $priority) {
                $activity->record('priority_detected', $priority['title'], $priority['message'], [
                    'key' => $priority['key'], 'severity' => $priority['severity'], 'route' => $priority['route'],
                ]);
            }
            foreach ($snapshot['workflow_recommendations'] as $recommendation) {
                $activity->record('workflow_recommendation', $recommendation['title'], $recommendation['message'], [
                    'key' => $recommendation['key'], 'severity' => $recommendation['severity'],
                    'recommended_action' => $recommendation['recommended_action'], 'route' => $recommendation['route'],
                ]);
            }
            return response()->json(['agr_autopilot' => $snapshot, 'agr_activity' => $activity->latest()]);
        }

        if ($request->boolean('agr_activity')) {
            return response()->json(['agr_activity' => $activity->latest((int) $request->input('limit', 20))]);
        }

        $apps = Aplicacion::query()->with(['empresa:id,nombre_comercial','proyecto:id,codigo,nombre,progreso','suscripcion'])->latest()->get();
        $cycles = $apps->map(fn(Aplicacion $app)=>['app'=>$app,'ciclo'=>$lifecycle->status($app)]);
        $attention = $cycles->filter(fn($row)=>in_array($row['ciclo']['estado'],['lista_entrega','gracia','suspendida'],true))->take(8)->values();

        $agrSnapshot = $autopilot->latest();

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
            'agr_activity'=>$activity->latest(),
            'requieren_atencion'=>$attention,
            'solicitudes_recientes'=>SolicitudSistema::with(['empresa:id,nombre_comercial','cliente:id,nombre'])->latest()->limit(5)->get(),
            'proyectos_recientes'=>Proyecto::with(['empresa:id,nombre_comercial','cliente:id,nombre'])->latest()->limit(5)->get(),
        ]);
    }
}
