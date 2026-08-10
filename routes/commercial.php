<?php

use App\Http\Controllers\{BillingController,ClientBillingController,ClientPaymentController,PaymentReviewController,SaasController};
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum','throttle:api'])->group(function (): void {
    Route::middleware('cliente')->group(function (): void {
        Route::get('mi/pagos', [ClientBillingController::class,'index']);
        Route::post('mi/pagos/proyectos/{proyecto}/comprobante', [ClientPaymentController::class,'proyecto']);
        Route::post('mi/pagos/suscripciones/{suscripcion}/comprobante', [ClientPaymentController::class,'suscripcion']);
        Route::get('mi/pagos/proyecto-pagos/{pago}/comprobante', [ClientPaymentController::class,'comprobanteProyecto']);
        Route::get('mi/pagos/suscripcion-pagos/{pago}/comprobante', [ClientPaymentController::class,'comprobanteSuscripcion']);
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

        Route::get('pagos/proyecto-pagos/{pago}/comprobante', [PaymentReviewController::class,'comprobanteProyecto']);
        Route::post('pagos/proyecto-pagos/{pago}/confirmar', [PaymentReviewController::class,'confirmarProyecto']);
        Route::post('pagos/proyecto-pagos/{pago}/rechazar', [PaymentReviewController::class,'rechazarProyecto']);
        Route::get('pagos/suscripcion-pagos/{pago}/comprobante', [PaymentReviewController::class,'comprobanteSuscripcion']);
        Route::post('pagos/suscripcion-pagos/{pago}/confirmar', [PaymentReviewController::class,'confirmarSuscripcion']);
        Route::post('pagos/suscripcion-pagos/{pago}/rechazar', [PaymentReviewController::class,'rechazarSuscripcion']);
    });
});
