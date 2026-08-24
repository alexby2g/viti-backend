<?php

namespace App\Services;

use App\Models\{Empresa, Mantenimiento, Proyecto, SolicitudSistema, Suscripcion};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AgrSystemGuardService
{
    public function scan(): array
    {
        $checks = [];
        $anomalies = [];
        $warnings = [];

        $checks[] = $this->databaseCheck($anomalies);
        $checks[] = $this->schemaCheck($anomalies);
        $checks[] = $this->relationshipCheck($anomalies);
        $checks[] = $this->workflowCheck($anomalies, $warnings);
        $checks[] = $this->failedJobsCheck($anomalies, $warnings);

        $critical = collect($anomalies)->where('severity', 'critical')->count();
        $high = collect($anomalies)->where('severity', 'high')->count();
        $warningCount = count($warnings);

        $status = $critical > 0 ? 'critical' : ($high > 0 || $warningCount > 0 ? 'attention' : 'healthy');
        $score = max(0, 100 - ($critical * 35) - ($high * 15) - min(25, $warningCount * 5));

        return [
            'generated_at' => now()->toIso8601String(),
            'status' => $status,
            'score' => $score,
            'checks' => $checks,
            'anomalies' => array_values($anomalies),
            'warnings' => array_values($warnings),
            'summary' => $this->summary($status, $critical, $high, $warningCount),
            'mode' => 'local_deterministic',
        ];
    }

    private function databaseCheck(array &$anomalies): array
    {
        try {
            DB::select('select 1');
            return ['key' => 'database', 'status' => 'ok', 'message' => 'La conexión con la base de datos responde correctamente.'];
        } catch (\Throwable $e) {
            $anomalies[] = [
                'key' => 'database_unavailable',
                'severity' => 'critical',
                'title' => 'Base de datos no disponible',
                'message' => 'AGR no pudo ejecutar una consulta de salud contra la base de datos.',
            ];
            return ['key' => 'database', 'status' => 'critical', 'message' => 'La conexión con la base de datos falló.'];
        }
    }

    private function schemaCheck(array &$anomalies): array
    {
        $tables = ['clientes', 'empresas', 'solicitudes_sistema', 'proyectos'];
        $missing = array_values(array_filter($tables, fn ($table) => !Schema::hasTable($table)));

        if ($missing) {
            $anomalies[] = [
                'key' => 'missing_core_tables',
                'severity' => 'critical',
                'title' => 'Estructura crítica incompleta',
                'message' => 'Faltan tablas esenciales de VITI: '.implode(', ', $missing).'.',
            ];
            return ['key' => 'schema', 'status' => 'critical', 'message' => 'Faltan tablas esenciales.'];
        }

        return ['key' => 'schema', 'status' => 'ok', 'message' => 'Las tablas esenciales de VITI están disponibles.'];
    }

    private function relationshipCheck(array &$anomalies): array
    {
        $orphanCompanies = Empresa::query()->whereNotNull('cliente_id')->whereDoesntHave('cliente')->count();
        $orphanRequests = SolicitudSistema::query()->whereDoesntHave('empresa')->orWhereDoesntHave('cliente')->count();
        $orphanProjects = Proyecto::query()->whereDoesntHave('empresa')->orWhereDoesntHave('cliente')->count();

        if ($orphanCompanies > 0) {
            $anomalies[] = [
                'key' => 'orphan_companies',
                'severity' => 'high',
                'title' => 'Empresas con vínculo roto',
                'message' => $orphanCompanies.' empresa(s) tienen un cliente asociado que ya no existe.',
            ];
        }

        if ($orphanRequests > 0) {
            $anomalies[] = [
                'key' => 'orphan_requests',
                'severity' => 'high',
                'title' => 'Solicitudes con referencias inválidas',
                'message' => $orphanRequests.' solicitud(es) no tienen empresa o cliente válido.',
            ];
        }

        if ($orphanProjects > 0) {
            $anomalies[] = [
                'key' => 'orphan_projects',
                'severity' => 'high',
                'title' => 'Proyectos con referencias inválidas',
                'message' => $orphanProjects.' proyecto(s) no tienen empresa o cliente válido.',
            ];
        }

        return [
            'key' => 'relationships',
            'status' => ($orphanCompanies || $orphanRequests || $orphanProjects) ? 'attention' : 'ok',
            'message' => 'AGR revisó los vínculos principales entre clientes, empresas, solicitudes y proyectos.',
            'details' => compact('orphanCompanies', 'orphanRequests', 'orphanProjects'),
        ];
    }

    private function workflowCheck(array &$anomalies, array &$warnings): array
    {
        $approvedWithoutProject = SolicitudSistema::query()->where('estado', 'aprobada')->whereDoesntHave('proyecto')->count();
        $staleSupport = Mantenimiento::query()->whereNotIn('estado', ['resuelto', 'cerrado'])->where('updated_at', '<', now()->subDays(7))->count();
        $activeProjectsWithoutRecentUpdate = Proyecto::query()->where('estado', 'activo')->where('updated_at', '<', now()->subDays(14))->count();
        $paymentAttention = Suscripcion::query()->whereIn('estado', ['gracia', 'suspendida'])->count();

        if ($approvedWithoutProject > 0) {
            $anomalies[] = [
                'key' => 'approved_without_project',
                'severity' => 'high',
                'title' => 'Solicitud aprobada sin proyecto',
                'message' => $approvedWithoutProject.' solicitud(es) aprobada(s) todavía no tienen proyecto.',
            ];
        }

        if ($staleSupport > 0) {
            $warnings[] = [
                'key' => 'stale_support',
                'title' => 'Soporte sin actividad reciente',
                'message' => $staleSupport.' atención(es) llevan más de 7 días sin actualización.',
            ];
        }

        if ($activeProjectsWithoutRecentUpdate > 0) {
            $warnings[] = [
                'key' => 'stale_projects',
                'title' => 'Proyecto sin avance reciente',
                'message' => $activeProjectsWithoutRecentUpdate.' proyecto(s) activos llevan más de 14 días sin actualización.',
            ];
        }

        if ($paymentAttention > 0) {
            $warnings[] = [
                'key' => 'payment_attention',
                'title' => 'Situaciones de pago',
                'message' => $paymentAttention.' suscripción(es) están en gracia o suspendidas.',
            ];
        }

        return [
            'key' => 'workflows',
            'status' => ($approvedWithoutProject > 0) ? 'attention' : ($staleSupport || $activeProjectsWithoutRecentUpdate || $paymentAttention ? 'warning' : 'ok'),
            'message' => 'AGR revisó procesos que pueden quedarse detenidos.',
            'details' => compact('approvedWithoutProject', 'staleSupport', 'activeProjectsWithoutRecentUpdate', 'paymentAttention'),
        ];
    }

    private function failedJobsCheck(array &$anomalies, array &$warnings): array
    {
        if (!Schema::hasTable('failed_jobs')) {
            return ['key' => 'failed_jobs', 'status' => 'not_available', 'message' => 'VITI no expone una tabla failed_jobs para esta instalación.'];
        }

        $recent = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHours(24))->count();
        if ($recent > 0) {
            $warnings[] = [
                'key' => 'failed_jobs',
                'title' => 'Trabajos fallidos',
                'message' => $recent.' trabajo(s) fallaron durante las últimas 24 horas.',
            ];
        }

        return [
            'key' => 'failed_jobs',
            'status' => $recent > 0 ? 'warning' : 'ok',
            'message' => $recent > 0 ? 'Se detectaron trabajos fallidos recientes.' : 'No se detectaron trabajos fallidos recientes.',
            'details' => ['recent_24h' => $recent],
        ];
    }

    private function summary(string $status, int $critical, int $high, int $warnings): string
    {
        return match ($status) {
            'critical' => 'AGR detectó '.$critical.' anomalía(s) críticas. VITI necesita revisión inmediata.',
            'attention' => 'AGR detectó '.$high.' anomalía(s) importantes y '.$warnings.' advertencia(s).',
            default => 'AGR completó la ronda y no encontró anomalías críticas en los controles disponibles.',
        };
    }
}
