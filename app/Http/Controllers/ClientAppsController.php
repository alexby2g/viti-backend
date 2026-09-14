<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,PeluqueriaAtencion,PeluqueriaCita,PeluqueriaCliente,PeluqueriaPago,PeluqueriaPersonal,PeluqueriaServicio};
use App\Services\{AppLifecycleService,SubscriptionAccessService,TenantContext};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientAppsController extends Controller
{
    public function index(Request $request, TenantContext $tenants, AppLifecycleService $lifecycle): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $apps = Aplicacion::query()
            ->where('empresa_id',$empresa->id)
            ->with(['empresa:id,nombre_comercial','proyecto:id,codigo,nombre,fase,estado,progreso','catalogo','suscripcion'])
            ->latest()->get();

        $items = $apps->map(function (Aplicacion $app) use ($lifecycle): array {
            $cycle = $lifecycle->status($app);
            $license = $this->licenseState($app, $cycle);
            $config = is_array($app->configuracion) ? $app->configuracion : [];
            $delivery = is_array($config['delivery'] ?? null) ? $config['delivery'] : [];
            $key = $app->catalogo?->clave;
            $internalRoute = match ($key) {
                'peluqueria' => '/mi-apps/peluqueria/inicio',
                'electrofrio' => '/mi-apps/electrofrio/inicio',
                'servicio-tecnico' => '/mi-apps/servicio-tecnico/inicio',
                default => $app->catalogo?->ruta_base,
            };
            $externalUrl = $this->httpsUrl($app->url);
            $betaUrl = $this->httpsUrl($delivery['beta_url'] ?? null);
            $apkUrl = $this->httpsUrl($delivery['apk_url'] ?? null);
            $route = $internalRoute ?: ($externalUrl ? '/apps/externa/'.$app->id : null);

            return [
                'id'=>$app->id,'nombre'=>$app->nombre,'slug'=>$app->slug,'version'=>$app->version,'entorno'=>$app->entorno,
                'estado'=>$app->estado,'estado_servicio'=>$license['estado'],'estado_mensaje'=>$license['mensaje'],'puede_usar'=>$license['permitida'],
                'acceso_cliente'=>(bool)$app->acceso_cliente,'entregado_at'=>$app->entregado_at,'empresa'=>$app->empresa,'catalogo'=>$app->catalogo,
                'proyecto'=>$app->proyecto ? ['codigo'=>$app->proyecto->codigo,'nombre'=>$app->proyecto->nombre,'fase'=>$app->proyecto->fase,'estado'=>$app->proyecto->estado,'progreso'=>$app->proyecto->progreso] : null,
                'suscripcion'=>$cycle['suscripcion'] ?? null,
                'modulos'=>$license['modulos'],
                'licencia'=>[
                    'puede_usar'=>$license['permitida'],
                    'control_pago'=>$license['control_pago'],
                    'control_modulos'=>$license['control_modulos'],
                ],
                'entrega'=>[
                    'beta_url'=>$betaUrl,
                    'apk_url'=>$license['permitida'] ? $apkUrl : null,
                    'apk_version'=>$delivery['apk_version'] ?? null,
                ],
                'ruta'=>$license['permitida'] ? $route : null,
                'es_externa'=>(bool)$externalUrl && !$internalRoute,
                'url_externa'=>$license['permitida'] && $externalUrl ? $externalUrl : null,
            ];
        })->values();

        return response()->json(['data'=>$items,'negocio'=>['id'=>$empresa->id,'nombre_comercial'=>$empresa->nombre_comercial]]);
    }

    public function license(Request $request, Aplicacion $aplicacion, TenantContext $tenants, AppLifecycleService $lifecycle): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        abort_unless((int)$aplicacion->empresa_id === (int)$empresa->id, 404, 'Aplicación no encontrada para esta empresa.');

        $aplicacion->loadMissing('suscripcion');
        $cycle = $lifecycle->status($aplicacion);
        $license = $this->licenseState($aplicacion, $cycle);
        $config = is_array($aplicacion->configuracion) ? $aplicacion->configuracion : [];
        $delivery = is_array($config['delivery'] ?? null) ? $config['delivery'] : [];

        return response()->json(['data'=>[
            'application_id'=>$aplicacion->id,
            'company_id'=>$empresa->id,
            'version'=>$aplicacion->version,
            'environment'=>$aplicacion->entorno,
            'status'=>$license['estado'],
            'allowed'=>$license['permitida'],
            'modules'=>$license['modulos'],
            'module_control'=>$license['control_modulos'],
            'payment_control'=>$license['control_pago'],
            'subscription'=>$cycle['suscripcion'] ?? null,
            'web_url'=>$license['permitida'] ? $this->httpsUrl($aplicacion->url) : null,
            'apk_url'=>$license['permitida'] ? $this->httpsUrl($delivery['apk_url'] ?? null) : null,
            'apk_version'=>$delivery['apk_version'] ?? null,
            'checked_at'=>now()->toIso8601String(),
        ]]);
    }

    public function peluqueria(Request $request, TenantContext $tenants, SubscriptionAccessService $access): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $app = Aplicacion::query()->where('empresa_id',$empresa->id)
            ->whereHas('catalogo',fn($q)=>$q->where('clave','peluqueria'))
            ->with(['catalogo','suscripcion','proyecto'])->latest()->first();
        abort_unless($app,404,'Este negocio no tiene una aplicación de peluquería asignada.');
        abort_unless((bool)$app->acceso_cliente,403,'La aplicación todavía no fue entregada. Contacta con Atención VITI.');
        $access->assertCanUse($app);

        $empresaId = (int)$empresa->id;
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        return response()->json(['data'=>[
            'aplicacion'=>['nombre'=>$app->nombre,'version'=>$app->version,'entorno'=>$app->entorno,'estado'=>$app->estado,'acceso_cliente'=>(bool)$app->acceso_cliente,'entregado_at'=>$app->entregado_at,'suscripcion'=>$access->statusFor($app)],
            'empresa'=>$empresa,
            'proyecto'=>$app->proyecto,
            'resumen'=>[
                'clientes'=>PeluqueriaCliente::where('empresa_id',$empresaId)->where('activo',true)->count(),
                'servicios'=>PeluqueriaServicio::where('empresa_id',$empresaId)->where('activo',true)->count(),
                'personal'=>PeluqueriaPersonal::where('empresa_id',$empresaId)->where('activo',true)->count(),
                'citas_hoy'=>PeluqueriaCita::where('empresa_id',$empresaId)->whereDate('fecha',$today)->count(),
                'atenciones_mes'=>PeluqueriaAtencion::where('empresa_id',$empresaId)->whereBetween('finalizada_at',[$monthStart,$monthEnd])->count(),
                'ingresos_mes'=>(float)PeluqueriaPago::where('empresa_id',$empresaId)->whereBetween('pagado_at',[$monthStart,$monthEnd])->sum('monto'),
            ],
        ]]);
    }

    private function licenseState(Aplicacion $app, array $cycle): array
    {
        $config = is_array($app->configuracion) ? $app->configuracion : [];
        $license = is_array($config['license'] ?? null) ? $config['license'] : [];
        $paymentControl = ($license['payment_required'] ?? true) !== false;
        $moduleControl = ($license['module_control'] ?? true) !== false;
        $ready = $app->entorno === 'produccion' && $app->estado === 'activo' && (bool)$app->acceso_cliente;
        $allowed = $ready && (!$paymentControl || (bool)($cycle['puede_usar'] ?? false));

        $state = (string)($cycle['estado'] ?? 'preparacion');
        $message = (string)($cycle['mensaje'] ?? 'La aplicación todavía no está disponible.');
        if ($ready && !$paymentControl) {
            $state = 'activa';
            $message = 'Aplicación activa sin control periódico de pago.';
        }

        return [
            'permitida'=>$allowed,
            'estado'=>$state,
            'mensaje'=>$message,
            'control_pago'=>$paymentControl,
            'control_modulos'=>$moduleControl,
            'modulos'=>$moduleControl ? array_values(array_filter((array)$app->modulos, fn($value) => is_string($value) && trim($value) !== '')) : null,
        ];
    }

    private function httpsUrl(mixed $value): ?string
    {
        $url = trim((string)$value);
        return filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://') ? $url : null;
    }
}
