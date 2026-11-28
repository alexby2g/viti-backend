<?php
use App\Http\Controllers\Sensaciones\MediaController;
use Illuminate\Support\Facades\Route;

Route::prefix('sensaciones')->group(function () {
    Route::get('media', [MediaController::class,'index'])->middleware('throttle:60,1');
    Route::get('media/{id}/file', [MediaController::class,'file'])->whereNumber('id')->middleware('throttle:120,1');
    Route::middleware(['auth:sanctum','superadmin','throttle:30,1'])->group(function () {
        Route::get('studio/media', [MediaController::class,'studio']);
        Route::post('studio/media', [MediaController::class,'store']);
        Route::patch('studio/media/{id}', [MediaController::class,'publish'])->whereNumber('id');
    });
});
