<?php

namespace App\Providers;

use App\Http\Controllers\PlatformBrandingController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BrandingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::prefix('api/v1')->middleware('api')->group(function (): void {
            Route::get('branding', [PlatformBrandingController::class, 'show']);

            Route::middleware(['auth:sanctum', 'throttle:api', 'superadmin'])->group(function (): void {
                Route::put('branding', [PlatformBrandingController::class, 'update']);
                Route::post('branding/logo', [PlatformBrandingController::class, 'uploadLogo']);
                Route::delete('branding/logo', [PlatformBrandingController::class, 'removeLogo']);
            });
        });
    }
}
