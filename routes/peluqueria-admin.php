<?php

use App\Http\Controllers\PeluqueriaTenantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum','throttle:api','platform_admin'])->group(function (): void {
    Route::get('apps/peluqueria/empresas', [PeluqueriaTenantController::class,'index']);
});
