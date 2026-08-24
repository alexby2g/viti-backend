<?php

use App\Models\SystemBackup;
use App\Services\{AgrAutopilotService,AgrHealthMonitorService,DatabaseBackupService};
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('viti:backup-database {--force : Crea una copia aunque exista un respaldo verificado reciente}', function (): int {
    $recent = SystemBackup::query()
        ->where('status','verified')
        ->whereNotNull('verified_at')
        ->where('verified_at','>=',now()->subHours(20))
        ->latest('verified_at')
        ->first();

    if ($recent && !$this->option('force')) {
        $this->info('VITI ya tiene un respaldo verificado reciente (#'.$recent->id.'). No se crea una copia redundante.');
        return 0;
    }

    $this->info('Creando respaldo lógico de PostgreSQL y verificando su integridad...');

    try {
        $backup = app(DatabaseBackupService::class)->create('scheduled',null);
        $this->info('Respaldo #'.$backup->id.' verificado correctamente.');
        return 0;
    } catch (Throwable $e) {
        report($e);
        $this->error('El respaldo automático no pudo completarse. Revisa Salud del sistema y los logs del backend.');
        return 1;
    }
})->purpose('Crea y verifica un respaldo privado de la base PostgreSQL de VITI.');

Artisan::command('agr:autopilot {--force : Ejecuta el análisis aunque el intervalo configurado no haya pasado}', function (AgrAutopilotService $autopilot, AgrHealthMonitorService $healthMonitor): int {
    if (!config('agr.autopilot.enabled', true)) {
        $this->warn('AGR Autopilot está deshabilitado por configuración.');
        return 0;
    }

    $healthMonitor->heartbeat();
    $snapshot = $autopilot->run();
    $this->info('AGR Autopilot: '.$snapshot['message']);
    $this->line('Salud: '.$snapshot['health'].' | Salud técnica: '.$snapshot['system_health']['status']);
    $this->line('Prioridades detectadas: '.count($snapshot['priorities']));
    $this->line('Anomalías técnicas: '.count($snapshot['system_health']['anomalies']));

    foreach ($snapshot['priorities'] as $priority) {
        $this->line(' - ['.strtoupper($priority['severity']).'] '.$priority['title'].': '.$priority['message']);
    }

    $this->line('Modo: '.$snapshot['mode'].' | Escrituras de negocio: bloqueadas.');
    return 0;
})->purpose('Analiza VITI de forma autónoma, comprueba salud técnica y no modifica datos de negocio.');

// Requiere un runner de Laravel Scheduler activo en la infraestructura.
Schedule::command('viti:backup-database')
    ->dailyAt('04:15')
    ->withoutOverlapping(60);

Schedule::command('agr:autopilot')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

Schedule::command('sanctum:prune-expired --hours=24')->daily();
