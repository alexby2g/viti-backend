<?php

use App\Http\Controllers\{BillingController,ClientBillingController,SaasController};
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum','throttle:api'])->group(function (): void {
    Route::middleware('cliente')->group(function (): void {
        Route::get('mi/pagos', [ClientBillingController::class,'index']);
    });

    Route::middleware(['platform_admin','superadmin'])->group(function (): void {
        Route::get('saas/resumen', [SaasController::class,'overview']);
        Route::get('saas/catalogo', [SaasController::class,'catalogo']);
        Route::post('saas/catalogo/{catalogoAplicacion}/provisionar', [SaasController::class,'provisionar']);
        Route::get('saas/planes', [SaasController::class,'planes']);
        Route::post('saas/planes', [SaasController::class,'guardarPlan']);
        Route::put('saas/planes/{plan}', [SaasController::class,'guardarPlan']);
        Route::put('saas/negocios/{empresa}/plan', [SaasController::class,'asignarPlan']);

        Route::get('pagos', [BillingController::class,'index']);
        Route::put('proyectos/{proyecto}/acuerdo-pago', [BillingController::class,'guardarAcuerdo']);
        Route::post('proyectos/{proyecto}/pagos', [BillingController::class,'registrarPagoProyecto']);
        Route::put('aplicaciones/{aplicacion}/suscripcion', [BillingController::class,'guardarSuscripcion']);
        Route::post('suscripciones/{suscripcion}/pagos', [BillingController::class,'registrarPagoSuscripcion']);
        Route::put('pagos/configuracion', [BillingController::class,'actualizarConfiguracion']);
        Route::post('pagos/configuracion/qr', [BillingController::class,'subirQr']);
    });
});
