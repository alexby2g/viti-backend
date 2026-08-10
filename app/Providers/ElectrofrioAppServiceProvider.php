<?php

namespace App\Providers;

use App\Http\Controllers\ClientElectrofrioController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ElectrofrioAppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'throttle:api', 'cliente'])
            ->prefix('api/v1/mi/apps/electrofrio')
            ->group(function (): void {
                Route::get('estado', [ClientElectrofrioController::class, 'estado']);
                Route::get('resumen', [ClientElectrofrioController::class, 'resumen']);

                Route::get('clientes', [ClientElectrofrioController::class, 'clientes']);
                Route::post('clientes', [ClientElectrofrioController::class, 'guardarCliente']);
                Route::put('clientes/{id}', [ClientElectrofrioController::class, 'actualizarCliente']);
                Route::delete('clientes/{id}', [ClientElectrofrioController::class, 'eliminarCliente']);

                Route::get('equipos', [ClientElectrofrioController::class, 'equipos']);
                Route::post('equipos', [ClientElectrofrioController::class, 'guardarEquipo']);
                Route::put('equipos/{id}', [ClientElectrofrioController::class, 'actualizarEquipo']);
                Route::delete('equipos/{id}', [ClientElectrofrioController::class, 'eliminarEquipo']);

                Route::get('tecnicos', [ClientElectrofrioController::class, 'tecnicos']);
                Route::post('tecnicos', [ClientElectrofrioController::class, 'guardarTecnico']);
                Route::put('tecnicos/{id}', [ClientElectrofrioController::class, 'actualizarTecnico']);
                Route::delete('tecnicos/{id}', [ClientElectrofrioController::class, 'eliminarTecnico']);

                Route::get('materiales', [ClientElectrofrioController::class, 'materiales']);
                Route::post('materiales', [ClientElectrofrioController::class, 'guardarMaterial']);
                Route::put('materiales/{id}', [ClientElectrofrioController::class, 'actualizarMaterial']);
                Route::delete('materiales/{id}', [ClientElectrofrioController::class, 'eliminarMaterial']);

                Route::get('ordenes', [ClientElectrofrioController::class, 'ordenes']);
                Route::post('ordenes', [ClientElectrofrioController::class, 'guardarOrden']);
                Route::put('ordenes/{id}', [ClientElectrofrioController::class, 'actualizarOrden']);
                Route::delete('ordenes/{id}', [ClientElectrofrioController::class, 'eliminarOrden']);
                Route::post('ordenes/{id}/decision', [ClientElectrofrioController::class, 'decision']);
                Route::post('ordenes/{id}/finalizar', [ClientElectrofrioController::class, 'finalizar']);
                Route::post('ordenes/{id}/materiales', [ClientElectrofrioController::class, 'usarMaterial']);
                Route::delete('ordenes/{orderId}/materiales/{materialId}', [ClientElectrofrioController::class, 'quitarMaterial']);
                Route::post('ordenes/{id}/pagos', [ClientElectrofrioController::class, 'registrarPago']);

                Route::get('pagos', [ClientElectrofrioController::class, 'pagos']);
                Route::get('garantias', [ClientElectrofrioController::class, 'garantias']);
                Route::get('historial', [ClientElectrofrioController::class, 'historial']);
            });
    }
}
