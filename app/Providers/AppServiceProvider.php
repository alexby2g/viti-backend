<?php

namespace App\Providers;

use App\Models\{Empresa,Proyecto,SolicitudSistema,Suscripcion,Usuario};
use App\Observers\{EmpresaObserver,ProyectoWorkflowObserver,SolicitudSistemaObserver,SolicitudWorkflowObserver,SuscripcionObserver,UsuarioObserver};
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
        Proyecto::observe(ProyectoWorkflowObserver::class);
        SolicitudSistema::observe(SolicitudWorkflowObserver::class);
        SolicitudSistema::observe(SolicitudSistemaObserver::class);
        Suscripcion::observe(SuscripcionObserver::class);
        Usuario::observe(UsuarioObserver::class);
        if (app()->environment('production')) URL::forceScheme('https');

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('acceso')).'|'.$request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);

        RateLimiter::for('api', function (Request $request): Limit {
            if ($request->user()) {
                return Limit::perMinute(300)->by('user:'.$request->user()->id);
            }
            return Limit::perMinute(120)->by('ip:'.$request->ip());
        });
    }
}
