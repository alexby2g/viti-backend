<?php

use App\Http\Controllers\{ElectrofrioConfiguracionController,ElectrofrioDashboardOperativoController,ElectrofrioDocumentacionController,ElectrofrioFichaTecnicaController,ElectrofrioFlujoEstadoController,ElectrofrioHistorialEquipoController,ElectrofrioOrdenOperativaController,ElectrofrioPagoOperativoController};
use Illuminate\Support\Facades\Route;

$electrofrioExtraRoutes = function (): void {
    Route::get('configuracion', [ElectrofrioConfiguracionController::class, 'show']);
    Route::put('configuracion', [ElectrofrioConfiguracionController::class, 'update']);
    Route::post('configuracion/logo', [ElectrofrioConfiguracionController::class, 'uploadLogo']);
    Route::get('dashboard-operativo', ElectrofrioDashboardOperativoController::class);

    Route::get('fichas-tecnicas', [ElectrofrioFichaTecnicaController::class, 'index']);
    Route::get('equipos/{equipoId}/ficha-tecnica', [ElectrofrioFichaTecnicaController::class, 'show'])->whereNumber('equipoId');
    Route::put('equipos/{equipoId}/ficha-tecnica', [ElectrofrioFichaTecnicaController::class, 'guardar'])->whereNumber('equipoId');

    Route::get('ordenes-operativas', [ElectrofrioOrdenOperativaController::class, 'index']);
    Route::post('ordenes-operativas', [ElectrofrioOrdenOperativaController::class, 'guardar']);
    Route::get('ordenes-operativas/{id}', [ElectrofrioOrdenOperativaController::class, 'show'])->whereNumber('id');
    Route::put('ordenes-operativas/{id}', [ElectrofrioOrdenOperativaController::class, 'actualizar'])->whereNumber('id');

    Route::get('flujo-estados', [ElectrofrioFlujoEstadoController::class, 'catalogo']);
    Route::get('ordenes/{id}/estados', [ElectrofrioFlujoEstadoController::class, 'historial'])->whereNumber('id');
    Route::post('ordenes/{id}/estado', [ElectrofrioFlujoEstadoController::class, 'cambiar'])->whereNumber('id');

    Route::post('ordenes/{id}/evidencias', [ElectrofrioDocumentacionController::class, 'subir'])->whereNumber('id');
    Route::get('evidencias/{evidencia}/descargar', [ElectrofrioDocumentacionController::class, 'descargar'])->whereNumber('evidencia');
    Route::delete('evidencias/{evidencia}', [ElectrofrioDocumentacionController::class, 'eliminar'])->whereNumber('evidencia');
    Route::get('ordenes/{id}/pdf', [ElectrofrioDocumentacionController::class, 'pdf'])->whereNumber('id');

    Route::get('pagos-operativos/referencias', [ElectrofrioPagoOperativoController::class, 'referencias']);
    Route::get('pagos-operativos', [ElectrofrioPagoOperativoController::class, 'index']);
    Route::post('ordenes/{id}/pagos-operativos', [ElectrofrioPagoOperativoController::class, 'guardar'])->whereNumber('id');
    Route::post('pagos-operativos/{id}/anular', [ElectrofrioPagoOperativoController::class, 'anular'])->whereNumber('id');

    Route::get('historial-equipos', [ElectrofrioHistorialEquipoController::class, 'index']);
    Route::get('historial-equipos/{id}', [ElectrofrioHistorialEquipoController::class, 'show'])->whereNumber('id');
};

Route::middleware(['auth:sanctum', 'throttle:api', 'platform_admin'])
    ->prefix('apps/electrofrio')
    ->group($electrofrioExtraRoutes);

Route::middleware(['auth:sanctum', 'throttle:api', 'cliente'])
    ->prefix('mi/apps/electrofrio')
    ->group($electrofrioExtraRoutes);
