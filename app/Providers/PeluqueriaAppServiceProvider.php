<?php

namespace App\Providers;

use App\Http\Controllers\AplicacionController;
use App\Http\Controllers\ClientPeluqueriaController;
use App\Http\Controllers\ClientPeluqueriaExtrasController;
use App\Http\Controllers\PeluqueriaExtrasController;
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

                Route::get('combos', [ClientPeluqueriaExtrasController::class,'combos']);
                Route::post('combos', [ClientPeluqueriaExtrasController::class,'guardarCombo']);
                Route::put('combos/{id}', [ClientPeluqueriaExtrasController::class,'actualizarCombo']);
                Route::delete('combos/{id}', [ClientPeluqueriaExtrasController::class,'eliminarCombo']);

                Route::get('productos', [ClientPeluqueriaExtrasController::class,'productos']);
                Route::post('productos', [ClientPeluqueriaExtrasController::class,'guardarProducto']);
                Route::put('productos/{id}', [ClientPeluqueriaExtrasController::class,'actualizarProducto']);
                Route::delete('productos/{id}', [ClientPeluqueriaExtrasController::class,'eliminarProducto']);
                Route::get('productos-movimientos', [ClientPeluqueriaExtrasController::class,'movimientos']);
                Route::post('productos-movimientos', [ClientPeluqueriaExtrasController::class,'registrarMovimiento']);
                Route::get('reportes', [ClientPeluqueriaExtrasController::class,'reportes']);

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
            ->prefix('api/v1')
            ->group(function (): void {
                Route::post('aplicaciones/{aplicacion}/entregar', [AplicacionController::class,'entregar']);
                Route::post('aplicaciones/{aplicacion}/revocar-acceso', [AplicacionController::class,'revocar']);

                Route::prefix('apps/peluqueria')->group(function (): void {
                    Route::get('combos', [PeluqueriaExtrasController::class,'combos']);
                    Route::post('combos', [PeluqueriaExtrasController::class,'guardarCombo']);
                    Route::put('combos/{id}', [PeluqueriaExtrasController::class,'actualizarCombo']);
                    Route::delete('combos/{id}', [PeluqueriaExtrasController::class,'eliminarCombo']);
                    Route::get('productos', [PeluqueriaExtrasController::class,'productos']);
                    Route::post('productos', [PeluqueriaExtrasController::class,'guardarProducto']);
                    Route::put('productos/{id}', [PeluqueriaExtrasController::class,'actualizarProducto']);
                    Route::delete('productos/{id}', [PeluqueriaExtrasController::class,'eliminarProducto']);
                    Route::get('productos-movimientos', [PeluqueriaExtrasController::class,'movimientos']);
                    Route::post('productos-movimientos', [PeluqueriaExtrasController::class,'registrarMovimiento']);
                    Route::get('reportes', [PeluqueriaExtrasController::class,'reportes']);
                });
            });
    }
}
