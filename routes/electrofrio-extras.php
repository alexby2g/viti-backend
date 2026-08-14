<?php

use App\Http\Controllers\{ElectrofrioFichaTecnicaController,ElectrofrioOrdenOperativaController};
use Illuminate\Support\Facades\Route;

$electrofrioExtraRoutes = function (): void {
    Route::get('fichas-tecnicas', [ElectrofrioFichaTecnicaController::class, 'index']);
    Route::get('equipos/{equipoId}/ficha-tecnica', [ElectrofrioFichaTecnicaController::class, 'show'])->whereNumber('equipoId');
    Route::put('equipos/{equipoId}/ficha-tecnica', [ElectrofrioFichaTecnicaController::class, 'guardar'])->whereNumber('equipoId');

    Route::get('ordenes-operativas', [ElectrofrioOrdenOperativaController::class, 'index']);
    Route::post('ordenes-operativas', [ElectrofrioOrdenOperativaController::class, 'guardar']);
    Route::get('ordenes-operativas/{id}', [ElectrofrioOrdenOperativaController::class, 'show'])->whereNumber('id');
    Route::put('ordenes-operativas/{id}', [ElectrofrioOrdenOperativaController::class, 'actualizar'])->whereNumber('id');
};

Route::middleware(['auth:sanctum', 'throttle:api', 'platform_admin'])
    ->prefix('apps/electrofrio')
    ->group($electrofrioExtraRoutes);

Route::middleware(['auth:sanctum', 'throttle:api', 'cliente'])
    ->prefix('mi/apps/electrofrio')
    ->group($electrofrioExtraRoutes);
