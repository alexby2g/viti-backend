<?php

use App\Http\Controllers\{PublicApplicationController,PublicNoPlanApplicationController,SaasController};
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api')->group(function (): void {
    Route::get('publico/planes', [SaasController::class, 'publicPlanes']);
    Route::get('publico/solicitud/catalogo', [PublicApplicationController::class, 'catalog']);
    Route::post('publico/solicitud/enviar', [PublicApplicationController::class, 'submit'])->middleware('throttle:5,1');
    Route::post('publico/solicitud/evaluacion', [PublicNoPlanApplicationController::class, 'submit'])->middleware('throttle:5,1');
    Route::get('meta/version', fn () => response()->json([
        'data' => [
            'producto' => 'VITI',
            'version' => (string) env('VITI_VERSION', '1.1.0'),
            'api' => 'v1',
            'release' => substr((string) (env('RENDER_GIT_COMMIT') ?: env('VITI_RELEASE_SHA') ?: 'local'), 0, 12),
        ],
    ]));
});
