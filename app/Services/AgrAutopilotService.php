<?php

namespace App\Services;

use App\Models\{Aplicacion, Cliente, Empresa, Mantenimiento, Proyecto, SolicitudSistema, Suscripcion};
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AgrAutopilotService
{
    private const CACHE_KEY = 'agr.autopilot.latest';

    public function run(AgrPermissionService $permissions, AgrHealthMonitorService $healthMonitor): array
    {
        $systemHealth = $healthMonitor->check();

        $snapshot = [
            'generated_at' => now()->toIso8601String(),
            'mode' => 'local_safe',
            'permissions' => $permissions->policy(),
            'autonomy' => [
                'can_read' => $permissions->can('read_data'),
                'can_navigate' => $permissions->can('navigate_modules'),
                'can_write_business_data' => $permissions->can('write_safe_data'),
                'requires_confirmation_for_writes' => true,
            ],
            'system_health' => $systemHealth,
            'metrics' => [
                'clients' => Cliente::query()->count(),
                'companies' => Empresa::query()->where('estado', 'activo')->count(),
                'projects_active' => Proyecto::query()->where('estado', 'activo')->count(),
                'requests_pending' => SolicitudSistema::query()->whereNotIn('estado', ['completada', 'rechazada', 'cancelada'])->count(),
                'requests_approved_without_project' => SolicitudSistema::query()
                    ->where('estado', 'aprobada')
                    ->whereDoesntHave('proyecto')
                    ->count(),
                'payments_attention' => Suscripcion::query()->whereIn('estado', ['gracia', 'suspendida'])->count(),
                'support_open' => Mantenimiento::query()->whereNotIn('estado', ['resuelto', 'cerrado'])->count(),
                'applications' => Aplicacion::query()->count(),
            ],
            'priorities' => [],
            'workflow_recommendations' => [],
            'safe_actions' => [
                'refresh_system_snapshot',
                'record_priority_state',
                'prepare_admin_attention',
                'prepare_project_from_approved_request',
            ],
            'blocked_actions' => [
                'delete_records',
                'charge_customer',
                'change_business_data',
                'close_support_without_confirmation',
                'convert_request_without_confirmation',
            ],
        ];

        $m = $snapshot['metrics'];

        if ($systemHealth['status'] !== 'healthy') {
            foreach ($systemHealth['anomalies'] as $anomaly) {
                $snapshot['priorities'][] = [
                    'key' => 'system_'.$anomaly['key'],
                    'severity' => $anomaly['severity'] ?? 'warning',
                    'title' => 'Anomalía del sistema',
                    'message' => $anomaly['message'],
                    'route' => '/dashboard',
                ];
            }
        }

        if ($m['requests_pending'] > 0) {
            $snapshot['priorities'][] = [
                'key' => 'pending_requests',
                'severity' => $m['requests_pending'] >= 5 ? 'high' : 'medium',
                'title' => 'Solicitudes pendientes',
                'message' => $m['requests_pending'].' solicitud(es) requieren revisión.',
                'route' => '/solicitudes',
            ];
        }

        if ($m['requests_approved_without_project'] > 0) {
            $snapshot['workflow_recommendations'][] = [
                'key' => 'approved_request_without_project',
                'severity' => 'high',
                'title' => 'Solicitud aprobada sin proyecto',
                'message' => $m['requests_approved_without_project'].' solicitud(es) aprobada(s) todavía no tienen proyecto asociado.',
                'recommended_action' => 'prepare_project_from_approved_request',
                'route' => '/solicitudes',
            ];
        }

        if ($m['payments_attention'] > 0) {
            $snapshot['priorities'][] = [
                'key' => 'payments_attention',
                'severity' => 'high',
                'title' => 'Situaciones de pago',
                'message' => $m['payments_attention'].' cuenta(s) están en gracia o suspendidas.',
                'route' => '/pagos',
            ];
        }

        if ($m['support_open'] > 0) {
            $snapshot['priorities'][] = [
                'key' => 'support_open',
                'severity' => $m['support_open'] >= 5 ? 'high' : 'medium',
                'title' => 'Soporte abierto',
                'message' => $m['support_open'].' atención(es) técnica(s) siguen abiertas.',
                'route' => '/mantenimientos',
            ];
        }

        if ($m['projects_active'] === 0 && $m['requests_pending'] > 0) {
            $snapshot['priorities'][] = [
                'key' => 'delivery_capacity',
                'severity' => 'medium',
                'title' => 'Capacidad de entrega',
                'message' => 'Hay solicitudes pendientes pero ningún proyecto activo.',
                'route' => '/proyectos',
            ];
        }

        $highWorkflow = collect($snapshot['workflow_recommendations'])->contains('severity', 'high');
        $hasHighPriority = collect($snapshot['priorities'])->contains('severity', 'high');
        $snapshot['health'] = ($systemHealth['status'] === 'critical')
            ? 'attention'
            : ((count($snapshot['priorities']) === 0 && count($snapshot['workflow_recommendations']) === 0)
                ? 'stable'
                : (($hasHighPriority || $highWorkflow || $systemHealth['status'] === 'warning') ? 'attention' : 'watch'));

        $snapshot['message'] = $this->messageFor($snapshot);

        Cache::put(self::CACHE_KEY, $snapshot, now()->addHours(6));
        Log::info('AGR Autopilot snapshot generated', [
            'health' => $snapshot['health'],
            'system_health' => $systemHealth['status'],
            'priorities' => count($snapshot['priorities']),
            'workflow_recommendations' => count($snapshot['workflow_recommendations']),
        ]);

        return $snapshot;
    }

    public function latest(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    private function messageFor(array $snapshot): string
    {
        $priorityCount = count($snapshot['priorities']);
        $workflowCount = count($snapshot['workflow_recommendations']);
        $total = $priorityCount + $workflowCount;

        if (($snapshot['system_health']['status'] ?? 'healthy') === 'critical') {
            return 'AGR detectó una anomalía crítica del sistema. La prioridad es revisar la salud técnica antes de continuar con operaciones administrativas.';
        }

        if (($snapshot['system_health']['status'] ?? 'healthy') === 'warning') {
            return 'AGR detectó una anomalía técnica y además revisó las prioridades operativas de VITI.';
        }

        if ($total === 0) {
            return 'AGR revisó VITI y no detectó incidencias, procesos detenidos ni anomalías técnicas en esta revisión.';
        }

        if ($workflowCount > 0 && $priorityCount > 0) {
            return 'AGR detectó '.$total.' puntos de atención: '.$priorityCount.' incidencias y '.$workflowCount.' siguiente(s) paso(s) de flujo recomendado(s).';
        }

        if ($workflowCount > 0) {
            return 'AGR detectó '.$workflowCount.' siguiente(s) paso(s) de flujo que conviene revisar.';
        }

        return match ($snapshot['health']) {
            'attention' => 'AGR detectó '.$priorityCount.' prioridad(es) que requieren atención.',
            default => 'AGR detectó '.$priorityCount.' punto(s) para vigilar.',
        };
    }
}
