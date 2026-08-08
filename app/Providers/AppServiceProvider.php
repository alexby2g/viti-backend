<?php

namespace App\Providers;

use App\Models\Empresa;
use App\Observers\EmpresaObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Empresa::observe(EmpresaObserver::class);

        if (app()->environment('production')) URL::forceScheme('https');

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('acceso')).'|'.$request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by((string) ($request->user()?->id ?: $request->ip())));
    }
}
