<?php

namespace App\Providers;

use App\Http\Controllers\BillingController;
use App\Http\Controllers\ClientBillingController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api','auth:sanctum','throttle:api','cliente'])
            ->prefix('api/v1/mi')
            ->group(function (): void {
                Route::get('pagos', [ClientBillingController::class,'index']);
            });

        Route::middleware(['api','auth:sanctum','throttle:api','superadmin'])
            ->prefix('api/v1')
            ->group(function (): void {
                Route::get('pagos', [BillingController::class,'index']);
                Route::put('pagos/configuracion', [BillingController::class,'actualizarConfiguracion']);
                Route::post('pagos/configuracion/qr', [BillingController::class,'subirQr']);
                Route::put('proyectos/{proyecto}/acuerdo-pago', [BillingController::class,'guardarAcuerdo']);
                Route::post('proyectos/{proyecto}/pagos', [BillingController::class,'registrarPagoProyecto']);
                Route::put('aplicaciones/{aplicacion}/suscripcion', [BillingController::class,'guardarSuscripcion']);
                Route::post('suscripciones/{suscripcion}/pagos', [BillingController::class,'registrarPagoSuscripcion']);
            });
    }
}
