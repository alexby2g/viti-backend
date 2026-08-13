<?php

namespace App\Http\Controllers;

use App\Models\{Auditoria,SystemBackup};
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = $this->databaseHealth();
        $queue = $this->queueHealth();
        $migrations = $this->migrationHealth();
        $audit = $this->auditHealth();
        $backup = $this->backupHealth();

        $overall = $database['ok'] && $migrations['ok'] && $queue['ok'] && $backup['ok'] ? 'ok' : 'warning';

        return response()->json([
            'data' => [
                'status' => $overall,
                'checked_at' => now()->toIso8601String(),
                'runtime' => [
                    'environment' => app()->environment(),
                    'php' => PHP_VERSION,
                    'laravel' => app()->version(),
                    'cache_store' => config('cache.default'),
                    'session_driver' => config('session.driver'),
                ],
                'database' => $database,
                'queue' => $queue,
                'migrations' => $migrations,
                'audit' => $audit,
                'backup' => $backup,
            ],
        ]);
    }

    private function databaseHealth(): array
    {
        $started = microtime(true);

        try {
            DB::select('select 1');

            return [
                'ok' => true,
                'driver' => DB::connection()->getDriverName(),
                'latency_ms' => round((microtime(true) - $started) * 1000, 1),
                'message' => 'La base de datos respondió correctamente.',
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'driver' => config('database.default'),
                'latency_ms' => round((microtime(true) - $started) * 1000, 1),
                'message' => 'La base de datos no respondió al chequeo interno.',
            ];
        }
    }

    private function queueHealth(): array
    {
        $connection = (string) config('queue.default');
        $pending = null;
        $failed = null;

        try {
            if ($connection === 'database' && Schema::hasTable((string) config('queue.connections.database.table', 'jobs'))) {
                $pending = DB::table((string) config('queue.connections.database.table', 'jobs'))->count();
            } elseif ($connection === 'sync') {
                $pending = 0;
            }

            if (Schema::hasTable((string) config('queue.failed.table', 'failed_jobs'))) {
                $failed = DB::table((string) config('queue.failed.table', 'failed_jobs'))->count();
            }

            return [
                'ok' => $failed === null || $failed === 0,
                'connection' => $connection,
                'pending' => $pending,
                'failed' => $failed,
                'message' => $failed && $failed > 0
                    ? 'Hay trabajos fallidos que requieren revisión.'
                    : ($connection === 'sync'
                        ? 'La cola está en modo síncrono; no requiere worker separado.'
                        : 'La cola no reporta trabajos fallidos.'),
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'connection' => $connection,
                'pending' => null,
                'failed' => null,
                'message' => 'No se pudo consultar el estado de la cola.',
            ];
        }
    }

    private function migrationHealth(): array
    {
        try {
            if (!Schema::hasTable('migrations')) {
                return [
                    'ok' => false,
                    'latest' => null,
                    'batch' => null,
                    'message' => 'No existe la tabla de migraciones.',
                ];
            }

            $latest = DB::table('migrations')
                ->orderByDesc('batch')
                ->orderByDesc('migration')
                ->first();

            return [
                'ok' => true,
                'latest' => $latest?->migration,
                'batch' => $latest?->batch,
                'message' => $latest
                    ? 'La base de datos tiene historial de migraciones disponible.'
                    : 'La tabla de migraciones existe pero no contiene registros.',
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'latest' => null,
                'batch' => null,
                'message' => 'No se pudo consultar el historial de migraciones.',
            ];
        }
    }

    private function auditHealth(): array
    {
        try {
            $last = Auditoria::query()->latest()->first();
            $last24h = Auditoria::query()->where('created_at', '>=', now()->subDay())->count();

            return [
                'ok' => true,
                'events_last_24h' => $last24h,
                'last_event_at' => $last?->created_at?->toIso8601String(),
                'last_action' => $last?->accion,
                'message' => $last
                    ? 'La auditoría está registrando actividad.'
                    : 'La auditoría está disponible, pero todavía no tiene eventos.',
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'events_last_24h' => null,
                'last_event_at' => null,
                'last_action' => null,
                'message' => 'No se pudo consultar la auditoría.',
            ];
        }
    }

    private function backupHealth(): array
    {
        try {
            if (!Schema::hasTable('system_backups')) {
                return [
                    'ok' => false,
                    'configured' => false,
                    'status' => 'migration_pending',
                    'message' => 'El registro de respaldos todavía no está disponible en la base de datos.',
                    'automatic' => false,
                ];
            }

            $latest = SystemBackup::query()->latest()->first();
            $latestVerified = SystemBackup::query()->where('status', 'verified')->whereNotNull('verified_at')->latest('verified_at')->first();
            $verifiedCount = SystemBackup::query()->where('status', 'verified')->count();

            if (!$latest) {
                return [
                    'ok' => false,
                    'configured' => true,
                    'status' => 'never_run',
                    'message' => 'La infraestructura de respaldo está lista, pero todavía no se creó un respaldo verificable.',
                    'automatic' => false,
                    'verified_count' => 0,
                    'last_verified_at' => null,
                ];
            }

            if (!$latestVerified) {
                return [
                    'ok' => false,
                    'configured' => true,
                    'status' => $latest->status === 'failed' ? 'failed' : 'pending_verification',
                    'message' => $latest->status === 'failed'
                        ? 'El último intento de respaldo falló y requiere revisión.'
                        : 'Existe un intento de respaldo, pero todavía no hay una copia verificada.',
                    'automatic' => false,
                    'verified_count' => 0,
                    'last_attempt_at' => $latest->completed_at?->toIso8601String() ?? $latest->started_at?->toIso8601String(),
                    'failure_reason' => $latest->failure_reason,
                ];
            }

            $hours = $latestVerified->verified_at->diffInHours(now());
            $fresh = $hours <= 48;

            return [
                'ok' => $fresh,
                'configured' => true,
                'status' => $fresh ? 'verified' : 'stale',
                'message' => $fresh
                    ? 'Existe un respaldo PostgreSQL verificado recientemente.'
                    : 'El último respaldo verificado tiene más de 48 horas y conviene renovarlo.',
                'automatic' => false,
                'verified_count' => $verifiedCount,
                'last_verified_at' => $latestVerified->verified_at?->toIso8601String(),
                'age_hours' => (int) $hours,
                'size_bytes' => $latestVerified->size_bytes,
                'checksum_sha256' => $latestVerified->checksum_sha256,
                'latest_attempt_status' => $latest->status,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'configured' => false,
                'status' => 'unavailable',
                'message' => 'No se pudo consultar el historial de respaldos.',
                'automatic' => false,
            ];
        }
    }
}
