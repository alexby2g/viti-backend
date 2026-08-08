<?php

namespace App\Providers;

use App\Http\Controllers\AplicacionController;
use App\Http\Controllers\ClientPeluqueriaController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PeluqueriaAppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api','auth:sanctum','throttle:api','cliente'])
            ->prefix('api/v1/mi/apps/peluqueria')
            ->group(function (): void {
                Route::get('estado', [ClientPeluqueriaController::class,'estado']);
                Route::get('resumen', [ClientPeluqueriaController::class,'resumen']);

                Route::get('clientes', [ClientPeluqueriaController::class,'clientes']);
                Route::post('clientes', [ClientPeluqueriaController::class,'guardarCliente']);
                Route::put('clientes/{id}', [ClientPeluqueriaController::class,'actualizarCliente']);
                Route::delete('clientes/{id}', [ClientPeluqueriaController::class,'eliminarCliente']);

                Route::get('servicios', [ClientPeluqueriaController::class,'servicios']);
                Route::post('servicios', [ClientPeluqueriaController::class,'guardarServicio']);
                Route::put('servicios/{id}', [ClientPeluqueriaController::class,'actualizarServicio']);
                Route::delete('servicios/{id}', [ClientPeluqueriaController::class,'eliminarServicio']);

                Route::get('personal', [ClientPeluqueriaController::class,'personal']);
                Route::post('personal', [ClientPeluqueriaController::class,'guardarPersonal']);
                Route::put('personal/{id}', [ClientPeluqueriaController::class,'actualizarPersonal']);
                Route::delete('personal/{id}', [ClientPeluqueriaController::class,'eliminarPersonal']);

                Route::get('citas', [ClientPeluqueriaController::class,'citas']);
                Route::post('citas', [ClientPeluqueriaController::class,'guardarCita']);
                Route::put('citas/{id}', [ClientPeluqueriaController::class,'actualizarCita']);
                Route::delete('citas/{id}', [ClientPeluqueriaController::class,'eliminarCita']);

                Route::get('atenciones', [ClientPeluqueriaController::class,'atenciones']);
                Route::post('atenciones', [ClientPeluqueriaController::class,'iniciarAtencion']);
                Route::post('atenciones/{id}/finalizar', [ClientPeluqueriaController::class,'finalizarAtencion']);
                Route::post('atenciones/{id}/pagos', [ClientPeluqueriaController::class,'registrarPago']);
                Route::get('historial', [ClientPeluqueriaController::class,'historial']);
            });

        Route::middleware(['api','auth:sanctum','throttle:api','superadmin'])
            ->prefix('api/v1/aplicaciones')
            ->group(function (): void {
                Route::post('{aplicacion}/entregar', [AplicacionController::class,'entregar']);
                Route::post('{aplicacion}/revocar-acceso', [AplicacionController::class,'revocar']);
            });
    }
}
