<?php

use App\Http\Controllers\NativeSessionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])
    ->prefix('mobile/sesiones')
    ->group(function (): void {
        Route::get('/', [NativeSessionController::class, 'index']);
        Route::delete('otras', [NativeSessionController::class, 'revokeOthers']);
        Route::delete('todas', [NativeSessionController::class, 'revokeAll']);
        Route::delete('{tokenId}', [NativeSessionController::class, 'revoke'])->whereNumber('tokenId');
    });
