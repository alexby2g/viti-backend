<?php

namespace App\Http\Middleware;

use App\Models\Aplicacion;
use App\Services\{SubscriptionAccessService,TenantContext};
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureServicioTecnicoClientAccess
{
    public function __construct(
        private TenantContext $tenants,
        private SubscriptionAccessService $subscriptions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && $user->rol === 'cliente', 403, 'Este acceso corresponde al equipo del negocio.');

        $empresa = $this->tenants->resolve($request);
        $app = Aplicacion::query()
            ->where('empresa_id',$empresa->id)
            ->whereHas('catalogo',fn($q)=>$q->where('clave','servicio-tecnico'))
            ->whereNotIn('estado',['retirado'])
            ->with(['catalogo','suscripcion'])
            ->latest('id')
            ->first();

        abort_unless($app, 404, 'Este negocio no tiene Servicio Técnico VITI asignado.');
        abort_unless((bool)$app->acceso_cliente, 403, 'Servicio Técnico VITI está terminado, pero todavía no fue entregado a tu empresa.');
        $this->subscriptions->assertCanUse($app);

        $module = (string)($request->route('st_module') ?: 'inicio');
        $this->tenants->assertModule($empresa,$module);
        $this->tenants->assertCanUse($user,$empresa,$module);

        if ((bool)$request->route('st_manage')) {
            $this->tenants->assertCanManage($user,$empresa);
        }

        $request->attributes->set('servicio_tecnico_app_id',$app->id);
        return $next($request);
    }
}
