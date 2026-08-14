<?php

use App\Http\Controllers\ElectrofrioFichaTecnicaController;
use Illuminate\Support\Facades\Route;

$technicalSheetRoutes = function (): void {
    Route::get('fichas-tecnicas', [ElectrofrioFichaTecnicaController::class, 'index']);
    Route::get('equipos/{equipoId}/ficha-tecnica', [ElectrofrioFichaTecnicaController::class, 'show'])->whereNumber('equipoId');
    Route::put('equipos/{equipoId}/ficha-tecnica', [ElectrofrioFichaTecnicaController::class, 'guardar'])->whereNumber('equipoId');
};

Route::middleware(['auth:sanctum', 'throttle:api', 'platform_admin'])
    ->prefix('apps/electrofrio')
    ->group($technicalSheetRoutes);

Route::middleware(['auth:sanctum', 'throttle:api', 'cliente'])
    ->prefix('mi/apps/electrofrio')
    ->group($technicalSheetRoutes);
