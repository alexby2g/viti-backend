<?php

namespace App\Http\Middleware;

use App\Models\Aplicacion;
use App\Services\{SubscriptionAccessService,TenantContext};
use Closure;
use Illuminate\Http\{JsonResponse,Request};
use Symfony\Component\HttpFoundation\Response;

class EnsureServicioTecnicoClientAccess
{
    private const FINANCIAL_KEYS = ['costo_servicio','descuento','total','pagado','saldo','pagos','por_cobrar','ingresos'];

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

        $canUsePayments = $this->tenants->hasModule($empresa,'pagos') && $this->tenants->canUse($user,$empresa,'pagos');
        if (!$canUsePayments) {
            foreach (['costo_servicio','descuento'] as $field) $request->request->remove($field);
        }

        $request->attributes->set('servicio_tecnico_app_id',$app->id);
        $response = $next($request);

        if (!$canUsePayments && $response instanceof JsonResponse) {
            $response->setData($this->maskFinancial($response->getData(true)));
        }

        return $response;
    }

    private function maskFinancial(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        foreach (array_keys($value) as $key) {
            if (in_array((string)$key,self::FINANCIAL_KEYS,true)) {
                unset($value[$key]);
                continue;
            }
            $value[$key] = $this->maskFinancial($value[$key]);
        }
        return $value;
    }
}
