<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Empresa,Mantenimiento,Proyecto,SolicitudSistema,Suscripcion};
use App\Services\{AppLifecycleService,AgrActivityService,AgrAssistantService,AgrAutopilotService,AgrIncidentService,AgrMemoryService,AgrPermissionService,AgrProjectConversionService,AgrSystemGuardService};
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
        AgrPermissionService $permissions,
        AgrSystemGuardService $guard,
        AgrIncidentService $incidents
    ): JsonResponse {
        if ($request->filled('agr')) {
            $agrInput = (string) $request->query('agr');
            $conversion = $projectConversion->handle($agrInput);
            if ($conversion !== null) return response()->json($conversion);
            return response()->json($memory->handle($agrInput, $assistant));
        }

        if ($request->boolean('agr_guard')) {
            $scan = $guard->scan();
            $activity->record('system_guard_scan', 'AGR completó una ronda del sistema', $scan['summary'], [
                'status' => $scan['status'],
                'score' => $scan['score'],
                'anomalies' => count($scan['anomalies']),
                'warnings' => count($scan['warnings']),
            ]);
            foreach ($scan['anomalies'] as $anomaly) {
                $activity->record('system_anomaly', $anomaly['title'], $anomaly['message'], [
                    'key' => $anomaly['key'],
                    'severity' => $anomaly['severity'],
                ]);
            }
            return response()->json(['agr_guard' => $scan, 'agr_activity' => $activity->latest()]);
        }

        if ($request->boolean('agr_autopilot')) {
            $snapshot = $autopilot->run($permissions);
            $guardScan = $guard->scan();
            $snapshot['system_guard'] = $guardScan;
            if ($guardScan['status'] !== 'healthy') {
                $snapshot['priorities'][] = [
                    'key' => 'system_guard',
                    'severity' => $guardScan['status'] === 'critical' ? 'critical' : 'high',
                    'title' => 'AGR System Guard',
                    'message' => $guardScan['summary'],
                    'route' => '/dashboard',
                ];
                $snapshot['health'] = $guardScan['status'] === 'critical' ? 'attention' : $snapshot['health'];
            }
            $snapshot['incidents'] = $incidents->fromSnapshot($snapshot);

            $activity->record('autopilot_review', 'AGR revisó VITI', $snapshot['message'], [
                'health' => $snapshot['health'],
                'permissions' => $snapshot['permissions'],
                'priorities' => count($snapshot['priorities']),
                'workflow_recommendations' => count($snapshot['workflow_recommendations']),
                'incidents' => count($snapshot['incidents']),
                'system_guard' => $guardScan['status'],
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
            foreach ($snapshot['incidents'] as $incident) {
                $activity->record('incident_detected', $incident['title'], $incident['summary'], [
                    'incident_id' => $incident['id'],
                    'severity' => $incident['severity'],
                    'category' => $incident['category'],
                    'signals' => $incident['signal_keys'],
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
