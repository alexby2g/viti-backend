<?php

use App\Http\Controllers\{PublicApplicationController,SaasController};
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api')->group(function (): void {
    Route::get('publico/planes', [SaasController::class, 'publicPlanes']);
    Route::get('publico/solicitud/catalogo', [PublicApplicationController::class, 'catalog']);
    Route::post('publico/solicitud/enviar', [PublicApplicationController::class, 'submit'])->middleware('throttle:5,1');
});
