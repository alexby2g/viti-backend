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
            $internalRoute = $app->catalogo?->ruta_base;
            $externalUrl = filter_var($app->url, FILTER_VALIDATE_URL) && str_starts_with((string)$app->url, 'https://')
                ? $app->url
                : null;
            $route = $internalRoute ?: ($externalUrl ? '/apps/externa/'.$app->id : null);
            $canOpen = (bool)$app->acceso_cliente && (bool)$cycle['puede_usar'];
            return [
                'id'=>$app->id,'nombre'=>$app->nombre,'slug'=>$app->slug,'version'=>$app->version,'entorno'=>$app->entorno,
                'estado'=>$app->estado,'estado_servicio'=>$cycle['estado'],'estado_mensaje'=>$cycle['mensaje'],'puede_usar'=>$cycle['puede_usar'],
                'acceso_cliente'=>(bool)$app->acceso_cliente,'entregado_at'=>$app->entregado_at,'empresa'=>$app->empresa,'catalogo'=>$app->catalogo,
                'proyecto'=>$app->proyecto ? ['codigo'=>$app->proyecto->codigo,'nombre'=>$app->proyecto->nombre,'fase'=>$app->proyecto->fase,'estado'=>$app->proyecto->estado,'progreso'=>$app->proyecto->progreso] : null,
                'suscripcion'=>$cycle['suscripcion'] ?? null,
                'ruta'=>$canOpen ? $route : null,
                'es_externa'=>(bool)$externalUrl && !$internalRoute,
                'url_externa'=>$canOpen && $externalUrl ? $externalUrl : null,
            ];
        })->values();

        return response()->json(['data'=>$items,'negocio'=>['id'=>$empresa->id,'nombre_comercial'=>$empresa->nombre_comercial]]);
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
}
