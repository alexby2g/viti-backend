<?php
use App\Http\Controllers\Sensaciones\MediaController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Sensaciones\SettingsController;
use App\Http\Controllers\Sensaciones\CollectionController;
use App\Http\Controllers\Sensaciones\DirectUploadController;

Route::prefix('sensaciones')->group(function () {
    Route::get('sitemap.xml',[\App\Http\Controllers\Sensaciones\SitemapController::class,'index'])->middleware('throttle:60,1');
    Route::get('settings',[SettingsController::class,'show'])->middleware('throttle:60,1');
    Route::get('collections',[CollectionController::class,'index'])->middleware('throttle:60,1');
    Route::get('media', [MediaController::class,'index'])->middleware('throttle:60,1');
    Route::get('media/{id}/file', [MediaController::class,'file'])->whereNumber('id')->middleware('throttle:120,1');
    Route::middleware(['auth:sanctum','superadmin','throttle:30,1'])->group(function () {
        Route::get('studio/settings',[SettingsController::class,'studio']);
        Route::put('studio/settings',[SettingsController::class,'save']);
        Route::get('studio/collections',[CollectionController::class,'studio']);
        Route::post('studio/collections',[CollectionController::class,'save']);
        Route::put('studio/collections/{id}',[CollectionController::class,'save'])->whereNumber('id');
        Route::post('studio/uploads',[DirectUploadController::class,'begin'])->middleware('throttle:6,1');
        Route::post('studio/uploads/{id}/finish',[DirectUploadController::class,'finish'])->whereUuid('id');
        Route::get('studio/media', [MediaController::class,'studio']);
        Route::post('studio/media', [MediaController::class,'store']);
        Route::patch('studio/media/{id}', [MediaController::class,'publish'])->whereNumber('id');
    });
});
