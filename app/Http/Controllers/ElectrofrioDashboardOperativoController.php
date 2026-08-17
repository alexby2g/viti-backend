<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Empresa};
use App\Services\{SubscriptionAccessService,TenantContext};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ElectrofrioDashboardOperativoController extends Controller
{
    private const CLOSED_STATES = ['finalizado','no_aprobado','cancelado'];

    public function __invoke(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        $modules = $tenants->effectiveModules($request->user(), $empresa);
        $has = fn (string $module): bool => $modules === null || in_array($module, $modules, true);
        $today = now()->toDateString();
        $inSevenDays = now()->addDays(7)->toDateString();

        $orders = DB::table('electrofrio_ordenes')->where('empresa_id', $empresa->id);
        $payments = DB::table('electrofrio_pagos')->where('empresa_id', $empresa->id)->where('estado', 'pagado');

        $agenda = DB::table('electrofrio_ordenes as o')
            ->join('electrofrio_clientes as c', 'c.id', '=', 'o.cliente_id')
            ->leftJoin('electrofrio_equipos as e', 'e.id', '=', 'o.equipo_id')
            ->leftJoin('electrofrio_tecnicos as t', 't.id', '=', 'o.tecnico_id')
            ->where('o.empresa_id', $empresa->id)
            ->whereDate('o.fecha_cita', $today)
            ->whereNotIn('o.estado_actual', self::CLOSED_STATES)
            ->orderBy('o.hora_cita')
            ->select(
                'o.id', 'o.codigo', 'o.hora_cita', 'o.etapa', 'o.estado_actual', 'o.prioridad', 'o.problema_reportado',
                'c.nombre as cliente_nombre', 'e.tipo as equipo_tipo', 'e.marca as equipo_marca',
                'e.modelo as equipo_modelo', 't.nombre as tecnico_nombre'
            )
            ->limit(20)
            ->get();

        $recent = DB::table('electrofrio_ordenes as o')
            ->join('electrofrio_clientes as c', 'c.id', '=', 'o.cliente_id')
            ->where('o.empresa_id', $empresa->id)
            ->orderByDesc('o.id')
            ->select('o.id', 'o.codigo', 'o.etapa', 'o.estado_actual', 'o.fecha_cita', 'o.tipo_servicio', 'c.nombre as cliente_nombre')
            ->limit(6)
            ->get();

        return response()->json(['data' => [
            'empresa' => [
                'id' => $empresa->id,
                'nombre_comercial' => $empresa->nombre_comercial,
            ],
            'citas_hoy' => (clone $orders)
                ->whereDate('fecha_cita', $today)
                ->whereNotIn('estado_actual', self::CLOSED_STATES)
                ->count(),
            'pendientes_diagnostico' => (clone $orders)
                ->whereIn('estado_actual', ['cita_programada','en_visita'])
                ->count(),
            'esperando_aprobacion' => (clone $orders)
                ->whereIn('estado_actual', ['propuesta_enviada','esperando_aprobacion'])
                ->count(),
            'servicios_activos' => (clone $orders)
                ->whereIn('estado_actual', ['aprobado','servicio_en_proceso','servicio_terminado','pendiente_pago'])
                ->count(),
            'pendientes_pago' => $has('pagos') ? (clone $orders)->where('estado_actual','pendiente_pago')->count() : null,
            'ordenes_abiertas' => (clone $orders)->whereNotIn('estado_actual', self::CLOSED_STATES)->count(),
            'por_cobrar' => $has('pagos') ? max(0,
                (float) (clone $orders)->sum('total') - (float) (clone $payments)->sum('monto')
            ) : null,
            'garantias_por_vencer' => $has('garantias') ? (clone $orders)
                ->whereNotNull('garantia_fin')
                ->whereDate('garantia_fin', '>=', $today)
                ->whereDate('garantia_fin', '<=', $inSevenDays)
                ->count() : null,
            'stock_bajo' => $has('inventario') ? DB::table('electrofrio_materiales')
                ->where('empresa_id', $empresa->id)
                ->where('activo', true)
                ->whereColumn('stock', '<=', 'stock_minimo')
                ->count() : null,
            'agenda_hoy' => $agenda,
            'ordenes_recientes' => $recent,
        ]]);
    }

    private function empresa(Request $request, TenantContext $tenants): Empresa
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanUse($request->user(), $empresa, 'inicio');

        if (!$request->user()->isPlatformAdmin()) {
            $app = Aplicacion::query()
                ->where('empresa_id', $empresa->id)
                ->whereHas('catalogo', fn ($query) => $query->where('clave', 'electrofrio'))
                ->with('suscripcion')
                ->latest('id')
                ->first();

            abort_unless($app, 404, 'Este negocio no tiene asignado el Sistema de Gestión de Servicios de Aire Acondicionado.');
            abort_unless((bool) $app->acceso_cliente, 403, 'El sistema todavía no fue entregado a este negocio.');
            abort_unless($app->estado === 'activo', 403, 'El acceso al sistema está suspendido.');
            app(SubscriptionAccessService::class)->assertCanUse($app);
        }

        return $empresa;
    }
}
