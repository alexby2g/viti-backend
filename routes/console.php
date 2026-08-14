<?php

use App\Models\SystemBackup;
use App\Services\DatabaseBackupService;
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

// Esta programación solo se ejecuta cuando la infraestructura tenga un runner
// de Laravel Scheduler activo. En Render Free actualmente queda preparada,
// pero no se presume activa hasta contar con ese proceso externo.
Schedule::command('viti:backup-database')
    ->dailyAt('04:15')
    ->withoutOverlapping(60);

Schedule::command('sanctum:prune-expired --hours=24')->daily();
