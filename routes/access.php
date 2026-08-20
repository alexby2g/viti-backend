<?php

use App\Http\Controllers\SolicitudAccesoVitiController;
use Illuminate\Support\Facades\Route;

Route::prefix('publico/acceso')->middleware('throttle:api')->group(function (): void {
    Route::get('codigo/{codigo}', [SolicitudAccesoVitiController::class,'resolveCode'])->middleware('throttle:login');
    Route::post('solicitar', [SolicitudAccesoVitiController::class,'store'])->middleware('throttle:login');
});

Route::middleware(['auth:sanctum','throttle:api','platform_admin','superadmin'])->group(function (): void {
    Route::get('accesos', [SolicitudAccesoVitiController::class,'index']);
    Route::post('accesos/{solicitud}/revision', [SolicitudAccesoVitiController::class,'markReview']);
    Route::post('accesos/{solicitud}/aprobar', [SolicitudAccesoVitiController::class,'approve']);
    Route::post('accesos/{solicitud}/rechazar', [SolicitudAccesoVitiController::class,'reject']);
});
