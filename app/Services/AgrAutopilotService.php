<?php

namespace App\Services;

use App\Models\{Aplicacion, Cliente, Empresa, Mantenimiento, Proyecto, SolicitudSistema, Suscripcion};
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AgrAutopilotService
{
    private const CACHE_KEY = 'agr.autopilot.latest';

    public function run(): array
    {
        $snapshot = [
            'generated_at' => now()->toIso8601String(),
            'mode' => 'local_safe',
            'autonomy' => [
                'can_read' => true,
                'can_navigate' => false,
                'can_write_business_data' => false,
                'requires_confirmation_for_writes' => true,
            ],
            'metrics' => [
                'clients' => Cliente::query()->count(),
                'companies' => Empresa::query()->where('estado', 'activo')->count(),
                'projects_active' => Proyecto::query()->where('estado', 'activo')->count(),
                'requests_pending' => SolicitudSistema::query()->whereNotIn('estado', ['completada', 'rechazada', 'cancelada'])->count(),
                'payments_attention' => Suscripcion::query()->whereIn('estado', ['gracia', 'suspendida'])->count(),
                'support_open' => Mantenimiento::query()->whereNotIn('estado', ['resuelto', 'cerrado'])->count(),
                'applications' => Aplicacion::query()->count(),
            ],
            'priorities' => [],
            'safe_actions' => [
                'refresh_system_snapshot',
                'record_priority_state',
                'prepare_admin_attention',
            ],
            'blocked_actions' => [
                'delete_records',
                'charge_customer',
                'change_business_data',
                'close_support_without_confirmation',
            ],
        ];

        $m = $snapshot['metrics'];

        if ($m['requests_pending'] > 0) {
            $snapshot['priorities'][] = [
                'key' => 'pending_requests',
                'severity' => $m['requests_pending'] >= 5 ? 'high' : 'medium',
                'title' => 'Solicitudes pendientes',
                'message' => $m['requests_pending'].' solicitud(es) requieren revisión.',
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

        $snapshot['health'] = count($snapshot['priorities']) === 0 ? 'stable' : (collect($snapshot['priorities'])->contains('severity', 'high') ? 'attention' : 'watch');
        $snapshot['message'] = $this->messageFor($snapshot);

        Cache::put(self::CACHE_KEY, $snapshot, now()->addHours(6));
        Log::info('AGR Autopilot snapshot generated', ['health' => $snapshot['health'], 'priorities' => count($snapshot['priorities'])]);

        return $snapshot;
    }

    public function latest(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    private function messageFor(array $snapshot): string
    {
        return match ($snapshot['health']) {
            'stable' => 'AGR revisó VITI y no detectó incidencias prioritarias.',
            'attention' => 'AGR detectó '.count($snapshot['priorities']).' prioridad(es) que requieren atención.',
            default => 'AGR detectó '.count($snapshot['priorities']).' punto(s) para vigilar.',
        };
    }
}
