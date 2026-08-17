<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,ElectrofrioConfiguracion,Empresa};
use App\Services\{SubscriptionAccessService,TenantContext};
use App\Support\Audit;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ElectrofrioOrdenOperativaController extends Controller
{
    private const STAGES = ['cita', 'diagnostico', 'propuesta', 'servicio', 'cerrada'];
    private const PRIORITIES = ['baja', 'normal', 'alta', 'urgente'];
    private const DECISIONS = ['pendiente', 'aceptado', 'rechazado'];
    private const DEFAULT_SERVICE_TYPES = [
        'Diagnóstico','Mantenimiento preventivo','Mantenimiento correctivo','Reparación',
        'Instalación','Desinstalación','Limpieza profunda','Carga de refrigerante',
    ];

    public function index(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        $filters = $request->validate([
            'buscar' => ['nullable', 'string', 'max:160'],
            'etapa' => ['nullable', Rule::in(self::STAGES)],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'equipo_id' => ['nullable', 'integer', 'min:1'],
            'tecnico_id' => ['nullable', 'integer', 'min:1'],
            'tipo_servicio' => ['nullable', 'string', 'max:120'],
            'prioridad' => ['nullable', Rule::in(self::PRIORITIES)],
            'decision_cliente' => ['nullable', Rule::in(self::DECISIONS)],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        $query = $this->baseQuery($empresa->id);
        $this->applyFilters($query, $filters);
        $items = $query->orderByDesc('o.id')->limit(500)->get();
        $items = $this->hydrate($request, $tenants, $empresa, $items);
        $config = ElectrofrioConfiguracion::query()->where('empresa_id', $empresa->id)->first();
        $configuredTypes = is_array($config?->tipos_servicio) && $config->tipos_servicio
            ? $config->tipos_servicio
            : self::DEFAULT_SERVICE_TYPES;
        $historicTypes = DB::table('electrofrio_ordenes')
            ->where('empresa_id', $empresa->id)
            ->whereNotNull('tipo_servicio')
            ->where('tipo_servicio', '!=', '')
            ->distinct()
            ->orderBy('tipo_servicio')
            ->pluck('tipo_servicio')
            ->all();

        return response()->json([
            'data' => $items,
            'meta' => [
                'resumen' => $this->summary($empresa->id),
                'tipos_servicio' => collect([...$configuredTypes, ...$historicTypes])
                    ->map(fn ($value) => trim((string) $value))
                    ->filter()
                    ->unique()
                    ->values(),
                'garantia_dias_default' => (int) ($config?->garantia_dias_default ?? 0),
            ],
        ]);
    }

    public function show(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        $item = $this->baseQuery($empresa->id)->where('o.id', $id)->first();
        abort_unless($item, 404, 'La orden solicitada no existe en este negocio.');

        $items = $this->hydrate($request, $tenants, $empresa, collect([$item]));
        return response()->json(['data' => $items->first()]);
    }

    public function guardar(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        $tipo = $this->tipoServicio($request);

        $id = DB::transaction(function () use ($request, $tenants, $empresa, $tipo): int {
            $response = app(ElectrofrioController::class)->guardarOrden($request, $tenants);
            $payload = $response->getData(true);
            $id = (int) data_get($payload, 'data.id');
            abort_unless($id > 0, 500, 'No se pudo identificar la orden creada.');

            DB::table('electrofrio_ordenes')
                ->where('empresa_id', $empresa->id)
                ->where('id', $id)
                ->update(['tipo_servicio' => $tipo, 'updated_at' => now()]);

            return $id;
        });

        Audit::log($request, 'electrofrio_orden_tipo_servicio_definido', null, 'Se definió el tipo de servicio de una orden del sistema de aire acondicionado.', [
            'orden_id' => $id,
            'tipo_servicio' => $tipo,
        ]);

        return $this->showResponse($request, $tenants, $id, 201);
    }

    public function actualizar(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        abort_unless(
            DB::table('electrofrio_ordenes')->where('empresa_id', $empresa->id)->where('id', $id)->exists(),
            404,
            'La orden solicitada no existe en este negocio.'
        );
        $tipo = $this->tipoServicio($request);

        DB::transaction(function () use ($request, $tenants, $empresa, $id, $tipo): void {
            app(ElectrofrioController::class)->actualizarOrden($request, $tenants, $id);
            DB::table('electrofrio_ordenes')
                ->where('empresa_id', $empresa->id)
                ->where('id', $id)
                ->update(['tipo_servicio' => $tipo, 'updated_at' => now()]);
        });

        Audit::log($request, 'electrofrio_orden_operativa_actualizada', null, 'Se actualizó una orden operativa del sistema de aire acondicionado.', [
            'orden_id' => $id,
            'tipo_servicio' => $tipo,
        ]);

        return $this->showResponse($request, $tenants, $id);
    }

    private function showResponse(Request $request, TenantContext $tenants, int $id, int $status = 200): JsonResponse
    {
        $response = $this->show($request, $tenants, $id);
        return response()->json($response->getData(true), $status);
    }

    private function empresa(Request $request, TenantContext $tenants): Empresa
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanUse($request->user(), $empresa, 'ordenes');

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

    private function tipoServicio(Request $request): ?string
    {
        $data = $request->validate([
            'tipo_servicio' => ['nullable', 'string', 'max:120'],
        ]);
        $value = trim((string) ($data['tipo_servicio'] ?? ''));
        return $value === '' ? null : $value;
    }

    private function baseQuery(int $empresaId): Builder
    {
        return DB::table('electrofrio_ordenes as o')
            ->join('electrofrio_clientes as c', 'c.id', '=', 'o.cliente_id')
            ->leftJoin('electrofrio_equipos as e', 'e.id', '=', 'o.equipo_id')
            ->leftJoin('electrofrio_tecnicos as t', 't.id', '=', 'o.tecnico_id')
            ->where('o.empresa_id', $empresaId)
            ->select(
                'o.*',
                'c.nombre as cliente_nombre',
                'c.telefono as cliente_telefono',
                'e.tipo as equipo_tipo',
                'e.marca as equipo_marca',
                'e.modelo as equipo_modelo',
                'e.serie as equipo_serie',
                'e.capacidad as equipo_capacidad',
                't.nombre as tecnico_nombre'
            );
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['buscar'])) {
            $term = '%'.trim($filters['buscar']).'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('o.codigo', 'like', $term)
                    ->orWhere('c.nombre', 'like', $term)
                    ->orWhere('c.telefono', 'like', $term)
                    ->orWhere('e.tipo', 'like', $term)
                    ->orWhere('e.marca', 'like', $term)
                    ->orWhere('e.modelo', 'like', $term)
                    ->orWhere('e.serie', 'like', $term)
                    ->orWhere('t.nombre', 'like', $term)
                    ->orWhere('o.tipo_servicio', 'like', $term)
                    ->orWhere('o.problema_reportado', 'like', $term)
                    ->orWhere('o.diagnostico', 'like', $term);
            });
        }

        foreach (['etapa', 'cliente_id', 'equipo_id', 'tecnico_id', 'tipo_servicio', 'prioridad', 'decision_cliente'] as $field) {
            if (array_key_exists($field, $filters) && $filters[$field] !== null && $filters[$field] !== '') {
                $query->where('o.'.$field, $filters[$field]);
            }
        }

        if (!empty($filters['desde'])) $query->whereDate('o.fecha_cita', '>=', $filters['desde']);
        if (!empty($filters['hasta'])) $query->whereDate('o.fecha_cita', '<=', $filters['hasta']);
    }

    private function hydrate(Request $request, TenantContext $tenants, Empresa $empresa, $items)
    {
        $ids = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (!$ids) return $items;

        $materials = $tenants->canUse($request->user(), $empresa, 'inventario')
            ? DB::table('electrofrio_orden_material as om')
                ->join('electrofrio_materiales as m', 'm.id', '=', 'om.material_id')
                ->where('om.empresa_id', $empresa->id)
                ->whereIn('om.orden_id', $ids)
                ->select('om.*', 'm.nombre as material_nombre', 'm.unidad as material_unidad')
                ->get()
                ->groupBy('orden_id')
            : collect();

        $payments = $tenants->canUse($request->user(), $empresa, 'pagos')
            ? DB::table('electrofrio_pagos')
                ->where('empresa_id', $empresa->id)
                ->whereIn('orden_id', $ids)
                ->where('estado', 'pagado')
                ->orderByDesc('pagado_at')
                ->get()
                ->groupBy('orden_id')
            : collect();

        $evidence = DB::table('electrofrio_evidencias')
            ->where('empresa_id', $empresa->id)
            ->whereIn('orden_id', $ids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('orden_id');
        $defaultWarranty = (int) (ElectrofrioConfiguracion::query()
            ->where('empresa_id', $empresa->id)
            ->value('garantia_dias_default') ?? 0);

        return $items->map(function ($order) use ($materials, $payments, $evidence, $defaultWarranty) {
            $order->materiales = ($materials[$order->id] ?? collect())->values();
            $order->pagos = ($payments[$order->id] ?? collect())->values();
            $order->evidencias = ($evidence[$order->id] ?? collect())->values();
            $order->pagado = (float) $order->pagos->sum('monto');
            $order->saldo = max(0, (float) $order->total - $order->pagado);
            $order->garantia_dias_default = $defaultWarranty;
            if ($order->etapa !== 'cerrada' && (int) ($order->garantia_dias ?? 0) === 0 && $defaultWarranty > 0) {
                $order->garantia_dias = $defaultWarranty;
            }
            return $order;
        });
    }

    private function summary(int $empresaId): array
    {
        $base = DB::table('electrofrio_ordenes')->where('empresa_id', $empresaId);
        $counts = (clone $base)
            ->selectRaw("COUNT(*) as total")
            ->selectRaw("SUM(CASE WHEN etapa = 'cita' THEN 1 ELSE 0 END) as cita")
            ->selectRaw("SUM(CASE WHEN etapa = 'diagnostico' THEN 1 ELSE 0 END) as diagnostico")
            ->selectRaw("SUM(CASE WHEN etapa = 'propuesta' AND decision_cliente = 'pendiente' THEN 1 ELSE 0 END) as propuesta")
            ->selectRaw("SUM(CASE WHEN etapa = 'servicio' THEN 1 ELSE 0 END) as servicio")
            ->selectRaw("SUM(CASE WHEN etapa = 'cerrada' THEN 1 ELSE 0 END) as cerrada")
            ->first();

        return [
            'total' => (int) ($counts->total ?? 0),
            'cita' => (int) ($counts->cita ?? 0),
            'diagnostico' => (int) ($counts->diagnostico ?? 0),
            'propuesta' => (int) ($counts->propuesta ?? 0),
            'servicio' => (int) ($counts->servicio ?? 0),
            'cerrada' => (int) ($counts->cerrada ?? 0),
        ];
    }
}