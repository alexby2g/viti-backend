<?php

use App\Http\Controllers\FitFamilyAdminController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->group(function (): void {
    Route::get('fitfamily/admin/categorias', [FitFamilyAdminController::class, 'categories']);
    Route::post('fitfamily/admin/categorias', [FitFamilyAdminController::class, 'storeCategory']);
    Route::get('fitfamily/admin/productos', [FitFamilyAdminController::class, 'products']);
    Route::post('fitfamily/admin/productos', [FitFamilyAdminController::class, 'storeProduct']);
});
