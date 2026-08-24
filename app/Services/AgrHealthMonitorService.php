<?php

namespace App\Services;

use App\Models\SolicitudSistema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AgrHealthMonitorService
{
    private const HEARTBEAT_KEY = 'agr.health.scheduler_heartbeat';

    public function check(): array
    {
        $checks = [];
        $anomalies = [];

        $checks[] = $this->databaseCheck($anomalies);
        $checks[] = $this->failedJobsCheck($anomalies);
        $checks[] = $this->workflowConsistencyCheck($anomalies);
        $checks[] = $this->schedulerCheck($anomalies);

        $level = 'healthy';
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'critical') {
                $level = 'critical';
                break;
            }
            if (($check['status'] ?? '') === 'warning') $level = 'warning';
        }

        $snapshot = [
            'checked_at' => now()->toIso8601String(),
            'status' => $level,
            'checks' => $checks,
            'anomalies' => $anomalies,
        ];

        Cache::put('agr.health.latest', $snapshot, now()->addHours(6));
        return $snapshot;
    }

    public function latest(): ?array
    {
        return Cache::get('agr.health.latest');
    }

    public function heartbeat(): void
    {
        Cache::put(self::HEARTBEAT_KEY, now()->toIso8601String(), now()->addMinutes(30));
    }

    private function databaseCheck(array &$anomalies): array
    {
        try {
            DB::select('select 1');
            return ['key' => 'database', 'status' => 'healthy', 'message' => 'La base de datos responde correctamente.'];
        } catch (\Throwable $e) {
            $anomalies[] = ['key' => 'database_unreachable', 'severity' => 'critical', 'message' => 'AGR no pudo consultar la base de datos.'];
            return ['key' => 'database', 'status' => 'critical', 'message' => 'La conexión con la base de datos falló.'];
        }
    }

    private function failedJobsCheck(array &$anomalies): array
    {
        if (!Schema::hasTable('failed_jobs')) {
            return ['key' => 'failed_jobs', 'status' => 'healthy', 'message' => 'No hay tabla de trabajos fallidos configurada.'];
        }

        try {
            $recent = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();
            if ($recent > 0) {
                $anomalies[] = ['key' => 'failed_jobs', 'severity' => $recent >= 5 ? 'critical' : 'warning', 'message' => $recent.' trabajo(s) fallaron durante la última hora.'];
                return ['key' => 'failed_jobs', 'status' => $recent >= 5 ? 'critical' : 'warning', 'message' => $recent.' trabajo(s) fallaron durante la última hora.'];
            }
            return ['key' => 'failed_jobs', 'status' => 'healthy', 'message' => 'No se detectaron trabajos fallidos recientes.'];
        } catch (\Throwable) {
            return ['key' => 'failed_jobs', 'status' => 'warning', 'message' => 'AGR no pudo revisar los trabajos fallidos.'];
        }
    }

    private function workflowConsistencyCheck(array &$anomalies): array
    {
        $count = SolicitudSistema::query()
            ->where('estado', 'convertida')
            ->whereDoesntHave('proyecto')
            ->count();

        if ($count > 0) {
            $anomalies[] = ['key' => 'converted_request_without_project', 'severity' => 'critical', 'message' => $count.' solicitud(es) figuran como convertidas pero no tienen proyecto asociado.'];
            return ['key' => 'workflow_consistency', 'status' => 'critical', 'message' => 'Se detectaron vínculos de flujo inconsistentes.'];
        }

        return ['key' => 'workflow_consistency', 'status' => 'healthy', 'message' => 'Los vínculos críticos de solicitud → proyecto son consistentes.'];
    }

    private function schedulerCheck(array &$anomalies): array
    {
        $heartbeat = Cache::get(self::HEARTBEAT_KEY);
        if (!$heartbeat) {
            return ['key' => 'scheduler', 'status' => 'warning', 'message' => 'AGR todavía no tiene un pulso reciente del Scheduler.'];
        }

        $last = \Carbon\Carbon::parse($heartbeat);
        if ($last->lt(now()->subMinutes(max(30, (int) config('agr.autopilot.interval_minutes', 15) * 2)))) {
            $anomalies[] = ['key' => 'scheduler_stale', 'severity' => 'warning', 'message' => 'El Scheduler no reporta un pulso reciente.'];
            return ['key' => 'scheduler', 'status' => 'warning', 'message' => 'El pulso del Scheduler parece atrasado.'];
        }

        return ['key' => 'scheduler', 'status' => 'healthy', 'message' => 'El Scheduler está reportando actividad reciente.'];
    }
}
