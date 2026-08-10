<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\TenantContext;
use App\Support\Audit;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ElectrofrioController extends Controller
{
    private ?array $activeModules = null;

    public function resumen(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $modules = $request->attributes->get('viti_plan_modules');
        $hasModule = fn (string $module): bool => $modules === null || in_array($module, $modules, true);
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $agenda = $this->ordenesQuery($empresaId)
            ->whereDate('o.fecha_cita', $today)
            ->where('o.etapa', '!=', 'cerrada')
            ->orderBy('o.hora_cita')
            ->limit(20)
            ->get();

        return response()->json(['data' => [
            'empresa' => DB::table('empresas')->select('id', 'nombre_comercial', 'actividad')->find($empresaId),
            'plan' => $request->attributes->get('viti_plan'),
            'clientes' => DB::table('electrofrio_clientes')->where('empresa_id', $empresaId)->where('activo', true)->count(),
            'equipos' => DB::table('electrofrio_equipos')->where('empresa_id', $empresaId)->where('activo', true)->count(),
            'citas_hoy' => DB::table('electrofrio_ordenes')->where('empresa_id', $empresaId)->whereDate('fecha_cita', $today)->count(),
            'ordenes_abiertas' => DB::table('electrofrio_ordenes')->where('empresa_id', $empresaId)->where('etapa', '!=', 'cerrada')->count(),
            'por_cobrar' => $hasModule('pagos') ? max(0, (float) DB::table('electrofrio_ordenes')->where('empresa_id', $empresaId)->sum('total')
                - (float) DB::table('electrofrio_pagos')->where('empresa_id', $empresaId)->where('estado', 'pagado')->sum('monto')) : null,
            'ingresos_mes' => $hasModule('pagos') ? (float) DB::table('electrofrio_pagos')->where('empresa_id', $empresaId)->where('estado', 'pagado')->whereBetween('pagado_at', [$monthStart, $monthEnd])->sum('monto') : null,
            'garantias_vigentes' => $hasModule('garantias') ? DB::table('electrofrio_ordenes')->where('empresa_id', $empresaId)->whereNotNull('garantia_fin')->whereDate('garantia_fin', '>=', $today)->count() : null,
            'stock_bajo' => $hasModule('inventario') ? DB::table('electrofrio_materiales')->where('empresa_id', $empresaId)->where('activo', true)->whereColumn('stock', '<=', 'stock_minimo')->count() : null,
            'agenda_hoy' => $agenda,
        ]]);
    }

    public function clientes(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $query = DB::table('electrofrio_clientes as c')
            ->leftJoin('usuarios as u','u.electrofrio_cliente_id','=','c.id')
            ->where('c.empresa_id',$empresaId)
            ->select('c.*','u.id as acceso_usuario_id','u.usuario as acceso_usuario','u.documento as acceso_documento','u.telefono as acceso_telefono','u.estado as acceso_estado');
        if ($request->filled('buscar')) {
            $term = '%'.trim((string)$request->input('buscar')).'%';
            $query->where(fn ($q) => $q->where('c.nombre','like',$term)->orWhere('c.telefono','like',$term)->orWhere('c.direccion','like',$term));
        }
        return response()->json(['data'=>$query->latest('c.id')->limit(150)->get()]);
    }

    public function guardarCliente(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $data = $this->datosCliente($request, $empresaId);
        $id = DB::table('electrofrio_clientes')->insertGetId($data + ['empresa_id' => $empresaId, 'created_at' => now(), 'updated_at' => now()]);
        Audit::log($request, 'electrofrio_cliente_creado', null, 'Se registró un cliente en Electrofrío.');
        return response()->json(['data' => DB::table('electrofrio_clientes')->find($id)], 201);
    }

    public function actualizarCliente(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $this->scoped('electrofrio_clientes', $empresaId, $id);
        $data = $this->datosCliente($request, $empresaId, $id);
        DB::table('electrofrio_clientes')->where('id', $id)->update($data + ['updated_at' => now()]);
        return response()->json(['data' => DB::table('electrofrio_clientes')->find($id)]);
    }

    public function eliminarCliente(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $this->scoped('electrofrio_clientes', $empresaId, $id);
        abort_if(DB::table('electrofrio_ordenes')->where('empresa_id', $empresaId)->where('cliente_id', $id)->exists(), 422, 'El cliente tiene órdenes registradas y no puede eliminarse. Puedes marcarlo como inactivo.');
        DB::table('electrofrio_clientes')->where('id', $id)->delete();
        return response()->json(status: 204);
    }

    public function equipos(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $items = DB::table('electrofrio_equipos as e')
            ->join('electrofrio_clientes as c', 'c.id', '=', 'e.cliente_id')
            ->where('e.empresa_id', $empresaId)
            ->select('e.*', 'c.nombre as cliente_nombre')
            ->when($request->filled('cliente_id'),fn($q)=>$q->where('e.cliente_id',$request->integer('cliente_id')))
            ->latest('e.id')->limit(150)->get();
        return response()->json(['data' => $items]);
    }

    public function guardarEquipo(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $data = $this->datosEquipo($request, $empresaId);
        $id = DB::table('electrofrio_equipos')->insertGetId($data + ['empresa_id' => $empresaId, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => DB::table('electrofrio_equipos')->find($id)], 201);
    }

    public function actualizarEquipo(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $this->scoped('electrofrio_equipos', $empresaId, $id);
        $data = $this->datosEquipo($request, $empresaId);
        DB::table('electrofrio_equipos')->where('id', $id)->update($data + ['updated_at' => now()]);
        return response()->json(['data' => DB::table('electrofrio_equipos')->find($id)]);
    }

    public function eliminarEquipo(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $this->scoped('electrofrio_equipos', $empresaId, $id);
        abort_if(DB::table('electrofrio_ordenes')->where('empresa_id', $empresaId)->where('equipo_id', $id)->exists(), 422, 'El equipo tiene historial de servicio y no puede eliminarse. Puedes marcarlo como inactivo.');
        DB::table('electrofrio_equipos')->where('id', $id)->delete();
        return response()->json(status: 204);
    }

    public function tecnicos(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $items = DB::table('electrofrio_tecnicos as t')->leftJoin('usuarios as u','u.id','=','t.usuario_id')
            ->where('t.empresa_id',$empresaId)->select('t.*','u.usuario','u.documento','u.estado as usuario_estado')->latest('t.id')->limit(150)->get();
        return response()->json(['data'=>$items]);
    }

    public function usuariosNegocio(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request,$tenants);
        $items = DB::table('empresa_usuario as eu')->join('usuarios as u','u.id','=','eu.usuario_id')
            ->where('eu.empresa_id',$empresaId)->where('eu.activo',true)->where('u.estado','activo')
            ->select('u.id','u.nombre','u.apellido','u.usuario','u.telefono')->orderBy('u.nombre')->get();
        return response()->json(['data'=>$items]);
    }

    public function guardarTecnico(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $data = $this->datosTecnico($request,$empresaId);
        $id = DB::table('electrofrio_tecnicos')->insertGetId($data + ['empresa_id' => $empresaId, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => DB::table('electrofrio_tecnicos')->find($id)], 201);
    }

    public function actualizarTecnico(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $this->scoped('electrofrio_tecnicos', $empresaId, $id);
        $data = $this->datosTecnico($request,$empresaId,$id);
        DB::table('electrofrio_tecnicos')->where('id', $id)->update($data + ['updated_at' => now()]);
        return response()->json(['data' => DB::table('electrofrio_tecnicos')->find($id)]);
    }

    public function eliminarTecnico(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $this->scoped('electrofrio_tecnicos', $empresaId, $id);
        DB::table('electrofrio_tecnicos')->where('id', $id)->delete();
        return response()->json(status: 204);
    }

    public function guardarAccesoCliente(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request,$tenants);
        $tenants->assertCanManage($request->user(),$tenants->resolve($request));
        $client = $this->scoped('electrofrio_clientes',$empresaId,$id);
        $request->merge([
            'usuario'=>Str::lower(trim((string)$request->input('usuario'))),
            'documento'=>preg_replace('/\D+/', '', (string)$request->input('documento')) ?: null,
            'telefono'=>preg_replace('/\D+/', '', (string)($request->input('telefono') ?: $client->telefono)) ?: null,
        ]);
        $existing = Usuario::query()->where('electrofrio_cliente_id',$id)->first();
        $data = $request->validate([
            'usuario'=>['required','alpha_dash','min:4','max:80','not_regex:/^\d+$/',Rule::unique('usuarios','usuario')->ignore($existing?->id)],
            'documento'=>['nullable','regex:/^[0-9]{5,15}$/',Rule::unique('usuarios','documento')->ignore($existing?->id)],
            'telefono'=>['nullable','regex:/^[0-9]{7,15}$/',Rule::unique('usuarios','telefono')->ignore($existing?->id)],
            'password'=>$existing?['nullable','confirmed',Password::min(8)->letters()->numbers()]:['required','confirmed',Password::min(8)->letters()->numbers()],
        ]);
        if ($existing) {
            if (empty($data['password'])) unset($data['password']);
            $existing->update($data+['nombre'=>$client->nombre,'rol'=>'cliente_negocio','estado'=>'activo']);
            $user=$existing->fresh();
        } else {
            $user=Usuario::create($data+['electrofrio_cliente_id'=>$id,'nombre'=>$client->nombre,'rol'=>'cliente_negocio','estado'=>'activo']);
        }
        Audit::log($request,'electrofrio_acceso_cliente_habilitado',$user,'Se habilitó el portal de un cliente final de Electrofrío.');
        return response()->json(['data'=>['id'=>$user->id,'usuario'=>$user->usuario,'telefono'=>$user->telefono,'documento'=>$user->documento,'estado'=>$user->estado]],$existing?200:201);
    }

    public function revocarAccesoCliente(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request,$tenants);
        $tenants->assertCanManage($request->user(),$tenants->resolve($request));
        $this->scoped('electrofrio_clientes',$empresaId,$id);
        $user=Usuario::query()->where('electrofrio_cliente_id',$id)->firstOrFail();
        $user->update(['estado'=>'inactivo']);
        Audit::log($request,'electrofrio_acceso_cliente_revocado',$user,'Se revocó el portal de un cliente final de Electrofrío.');
        return response()->json(['message'=>'Acceso del cliente revocado.']);
    }

    public function materiales(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        return response()->json(['data' => DB::table('electrofrio_materiales')->where('empresa_id', $empresaId)->latest('id')->limit(500)->get()]);
    }

    public function guardarMaterial(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $data = $this->datosMaterial($request, $empresaId);
        $id = DB::table('electrofrio_materiales')->insertGetId($data + ['empresa_id' => $empresaId, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => DB::table('electrofrio_materiales')->find($id)], 201);
    }

    public function actualizarMaterial(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $this->scoped('electrofrio_materiales', $empresaId, $id);
        $data = $this->datosMaterial($request, $empresaId, $id);
        DB::table('electrofrio_materiales')->where('id', $id)->update($data + ['updated_at' => now()]);
        return response()->json(['data' => DB::table('electrofrio_materiales')->find($id)]);
    }

    public function eliminarMaterial(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $this->scoped('electrofrio_materiales', $empresaId, $id);
        abort_if(DB::table('electrofrio_orden_material')->where('material_id', $id)->exists(), 422, 'El material ya forma parte del historial de una orden. Puedes marcarlo como inactivo.');
        DB::table('electrofrio_materiales')->where('id', $id)->delete();
        return response()->json(status: 204);
    }

    public function ordenes(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $query = $this->ordenesQuery($empresaId);
        if ($request->filled('etapa')) $query->where('o.etapa', $request->string('etapa'));
        if ($request->filled('cliente_id')) $query->where('o.cliente_id', $request->integer('cliente_id'));
        return response()->json(['data' => $this->hydrateOrders($query->latest('o.id')->limit(500)->get(), $empresaId)]);
    }

    public function guardarOrden(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $data = $this->datosOrden($request, $empresaId);

        $id = DB::transaction(function () use ($data, $empresaId): int {
            $id = DB::table('electrofrio_ordenes')->insertGetId($data + [
                'empresa_id' => $empresaId,
                'codigo' => 'TEMP-'.bin2hex(random_bytes(6)),
                'etapa' => 'cita',
                'decision_cliente' => 'pendiente',
                'costo_materiales' => 0,
                'total' => max(0, (float)($data['costo_mano_obra'] ?? 0) - (float)($data['descuento'] ?? 0)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('electrofrio_ordenes')->where('id', $id)->update(['codigo' => 'EF-'.now()->format('Ym').'-'.str_pad((string)$id, 5, '0', STR_PAD_LEFT)]);
            return $id;
        });

        Audit::log($request, 'electrofrio_orden_creada', null, 'Se creó una orden de servicio en Electrofrío.');
        return response()->json(['data' => $this->findOrder($empresaId, $id)], 201);
    }

    public function actualizarOrden(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $order = $this->scoped('electrofrio_ordenes', $empresaId, $id);
        abort_if($order->etapa === 'cerrada', 422, 'La orden está cerrada. El historial no puede modificarse.');
        $data = $this->datosOrden($request, $empresaId, $id);
        if ($order->decision_cliente === 'aceptado') $data['etapa'] = 'servicio';
        elseif (!empty($data['propuesta'])) $data['etapa'] = 'propuesta';
        elseif (!empty($data['diagnostico'])) $data['etapa'] = 'diagnostico';
        $data['total'] = max(0, (float)($data['costo_mano_obra'] ?? $order->costo_mano_obra) + (float)$order->costo_materiales - (float)($data['descuento'] ?? $order->descuento));
        $paid = (float) DB::table('electrofrio_pagos')->where('empresa_id', $empresaId)->where('orden_id', $id)->where('estado', 'pagado')->sum('monto');
        abort_if($data['total'] + 0.001 < $paid, 422, 'El nuevo total no puede ser menor al monto ya pagado.');
        DB::table('electrofrio_ordenes')->where('id', $id)->update($data + ['updated_at' => now()]);
        return response()->json(['data' => $this->findOrder($empresaId, $id)]);
    }

    public function eliminarOrden(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $this->scoped('electrofrio_ordenes', $empresaId, $id);
        abort_if(DB::table('electrofrio_pagos')->where('orden_id', $id)->exists() || DB::table('electrofrio_orden_material')->where('orden_id', $id)->exists(), 422, 'La orden tiene movimientos de materiales o pagos y debe conservarse en el historial.');
        DB::table('electrofrio_ordenes')->where('id', $id)->delete();
        return response()->json(status: 204);
    }

    public function decision(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $order = $this->scoped('electrofrio_ordenes', $empresaId, $id);
        abort_if($order->etapa === 'cerrada', 422, 'La orden ya está cerrada.');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['aceptado', 'rechazado'])],
            'motivo_rechazo' => ['nullable', 'string', 'max:3000', 'required_if:decision,rechazado'],
        ]);
        abort_if($data['decision'] === 'aceptado' && empty($order->diagnostico), 422, 'Registra el diagnóstico antes de aceptar el servicio.');
        abort_if($data['decision'] === 'aceptado' && empty($order->propuesta), 422, 'Registra la propuesta antes de aceptar el servicio.');
        DB::table('electrofrio_ordenes')->where('id', $id)->update([
            'decision_cliente' => $data['decision'],
            'motivo_rechazo' => $data['motivo_rechazo'] ?? null,
            'decision_at' => now(),
            'etapa' => $data['decision'] === 'aceptado' ? 'servicio' : 'cerrada',
            'finalizada_at' => $data['decision'] === 'rechazado' ? now() : null,
            'updated_at' => now(),
        ]);
        return response()->json(['data' => $this->findOrder($empresaId, $id)]);
    }

    public function finalizar(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        if ($request->integer('garantia_dias', 0) > 0) {
            abort_unless($this->hasModule($request, 'garantias'), 403, 'Las garantías no forman parte del plan VITI asignado.');
        }
        $order = $this->scoped('electrofrio_ordenes', $empresaId, $id);
        abort_unless($order->decision_cliente === 'aceptado', 422, 'El cliente debe aceptar la propuesta antes de finalizar el servicio.');
        abort_if($order->etapa === 'cerrada', 422, 'La orden ya está cerrada.');
        $data = $request->validate([
            'trabajo_realizado' => ['required', 'string', 'max:8000'],
            'recomendaciones' => ['nullable', 'string', 'max:5000'],
            'garantia_dias' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'condiciones_garantia' => ['nullable', 'string', 'max:5000'],
        ]);
        $days = (int)($data['garantia_dias'] ?? 0);
        $start = $days > 0 ? now()->toDateString() : null;
        $end = $days > 0 ? now()->addDays($days)->toDateString() : null;
        DB::table('electrofrio_ordenes')->where('id', $id)->update([
            'trabajo_realizado' => $data['trabajo_realizado'],
            'recomendaciones' => $data['recomendaciones'] ?? null,
            'garantia_dias' => $days,
            'garantia_inicio' => $start,
            'garantia_fin' => $end,
            'condiciones_garantia' => $data['condiciones_garantia'] ?? null,
            'etapa' => 'cerrada',
            'finalizada_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['data' => $this->findOrder($empresaId, $id)]);
    }

    public function usarMaterial(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $order = $this->scoped('electrofrio_ordenes', $empresaId, $id);
        abort_if($order->etapa === 'cerrada', 422, 'No se pueden modificar materiales de una orden cerrada.');
        $data = $request->validate([
            'material_id' => ['required', 'integer'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
        ]);
        $materialId = (int)$data['material_id'];
        $quantity = (float)$data['cantidad'];

        DB::transaction(function () use ($empresaId, $id, $materialId, $quantity): void {
            $material = DB::table('electrofrio_materiales')->where('empresa_id', $empresaId)->where('id', $materialId)->lockForUpdate()->first();
            abort_unless($material && $material->activo, 422, 'El material seleccionado no está disponible.');
            $existing = DB::table('electrofrio_orden_material')->where('orden_id', $id)->where('material_id', $materialId)->first();
            $previous = (float)($existing->cantidad ?? 0);
            $delta = $quantity - $previous;
            abort_if($delta > (float)$material->stock, 422, 'No hay stock suficiente para registrar esa cantidad.');

            DB::table('electrofrio_materiales')->where('id', $materialId)->update(['stock' => (float)$material->stock - $delta, 'updated_at' => now()]);
            DB::table('electrofrio_orden_material')->updateOrInsert(
                ['orden_id' => $id, 'material_id' => $materialId],
                [
                    'empresa_id' => $empresaId,
                    'cantidad' => $quantity,
                    'costo_unitario' => (float)$material->costo_unitario,
                    'subtotal' => $quantity * (float)$material->costo_unitario,
                    'created_at' => $existing?->created_at ?? now(),
                    'updated_at' => now(),
                ]
            );
            $this->recalcularOrden($empresaId, $id);
        });

        return response()->json(['data' => $this->findOrder($empresaId, $id)]);
    }

    public function quitarMaterial(Request $request, TenantContext $tenants, int $orderId, int $materialId): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $order = $this->scoped('electrofrio_ordenes', $empresaId, $orderId);
        abort_if($order->etapa === 'cerrada', 422, 'No se pueden modificar materiales de una orden cerrada.');

        DB::transaction(function () use ($empresaId, $orderId, $materialId): void {
            $usage = DB::table('electrofrio_orden_material')->where('empresa_id', $empresaId)->where('orden_id', $orderId)->where('material_id', $materialId)->lockForUpdate()->first();
            abort_unless($usage, 404, 'El material no forma parte de esta orden.');
            DB::table('electrofrio_materiales')->where('empresa_id', $empresaId)->where('id', $materialId)->increment('stock', (float)$usage->cantidad, ['updated_at' => now()]);
            DB::table('electrofrio_orden_material')->where('id', $usage->id)->delete();
            $this->recalcularOrden($empresaId, $orderId);
        });

        return response()->json(['data' => $this->findOrder($empresaId, $orderId)]);
    }

    public function pagos(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $items = DB::table('electrofrio_pagos as p')
            ->join('electrofrio_ordenes as o', 'o.id', '=', 'p.orden_id')
            ->join('electrofrio_clientes as c', 'c.id', '=', 'o.cliente_id')
            ->where('p.empresa_id', $empresaId)
            ->select('p.*', 'o.codigo as orden_codigo', 'c.nombre as cliente_nombre')
            ->latest('p.pagado_at')->limit(500)->get();
        return response()->json(['data' => $items]);
    }

    public function registrarPago(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $order = $this->scoped('electrofrio_ordenes', $empresaId, $id);
        $data = $request->validate([
            'monto' => ['required', 'numeric', 'gt:0'],
            'metodo' => ['required', Rule::in(['efectivo', 'qr', 'transferencia', 'tarjeta', 'otro'])],
            'referencia' => ['nullable', 'string', 'max:120'],
        ]);
        $paid = (float) DB::table('electrofrio_pagos')->where('empresa_id', $empresaId)->where('orden_id', $id)->where('estado', 'pagado')->sum('monto');
        abort_if($paid + (float)$data['monto'] > (float)$order->total + 0.001, 422, 'El pago supera el saldo pendiente de la orden.');
        $paymentId = DB::table('electrofrio_pagos')->insertGetId($data + [
            'empresa_id' => $empresaId,
            'orden_id' => $id,
            'estado' => 'pagado',
            'pagado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['data' => DB::table('electrofrio_pagos')->find($paymentId)], 201);
    }

    public function garantias(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $items = $this->ordenesQuery($empresaId)
            ->whereNotNull('o.garantia_fin')
            ->orderByDesc('o.garantia_fin')
            ->limit(500)->get();
        return response()->json(['data' => $this->hydrateOrders($items, $empresaId)]);
    }

    public function historial(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request, $tenants);
        $items = $this->ordenesQuery($empresaId)
            ->where('o.etapa', 'cerrada')
            ->latest('o.finalizada_at')
            ->limit(500)->get();
        return response()->json(['data' => $this->hydrateOrders($items, $empresaId)]);
    }

    private function empresaId(Request $request, TenantContext $tenants): int
    {
        $resolved = $tenants->resolve($request);
        $hasApp = DB::table('aplicaciones as a')->join('catalogo_aplicaciones as c','c.id','=','a.catalogo_aplicacion_id')
            ->where('a.empresa_id',$resolved->id)->where('c.clave','electrofrio')->where('a.estado','activo')->whereNull('a.deleted_at')->exists();
        abort_unless($hasApp,404,'Este negocio no tiene Electrofrío activo.');

        $module = $this->moduleForAction((string) $request->route()?->getActionMethod());
        if ($module !== null) {
            $tenants->assertModule($resolved,$module);
            $tenants->assertCanUse($request->user(),$resolved,$module);
        }

        $modules = $tenants->effectiveModules($request->user(),$resolved);
        $this->activeModules = $modules;
        $request->attributes->set('viti_plan_modules', $modules);
        $request->attributes->set('viti_plan', $resolved->planViti ? [
            'codigo' => $resolved->planViti->codigo,
            'nombre' => $resolved->planViti->nombre,
            'precio_proyecto' => $resolved->planViti->precio_proyecto,
            'modulos' => $modules,
        ] : null);
        return (int)$resolved->id;
    }

    private function moduleForAction(string $action): ?string
    {
        return match ($action) {
            'resumen' => 'inicio',
            'clientes', 'guardarCliente', 'actualizarCliente', 'eliminarCliente', 'guardarAccesoCliente', 'revocarAccesoCliente' => 'clientes',
            'equipos', 'guardarEquipo', 'actualizarEquipo', 'eliminarEquipo' => 'equipos',
            'tecnicos', 'usuariosNegocio', 'guardarTecnico', 'actualizarTecnico', 'eliminarTecnico' => 'tecnicos',
            'materiales', 'guardarMaterial', 'actualizarMaterial', 'eliminarMaterial', 'usarMaterial', 'quitarMaterial' => 'inventario',
            'ordenes', 'guardarOrden', 'actualizarOrden', 'eliminarOrden', 'decision', 'finalizar' => 'ordenes',
            'pagos', 'registrarPago' => 'pagos',
            'garantias' => 'garantias',
            'historial' => 'historial',
            default => null,
        };
    }

    private function hasModule(Request $request, string $module): bool
    {
        $modules = $request->attributes->get('viti_plan_modules');
        return $modules === null || in_array($module, $modules, true);
    }

    private function datosCliente(Request $request, int $empresaId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:180'],
            'telefono' => ['nullable', 'string', 'max:30', Rule::unique('electrofrio_clientes', 'telefono')->where(fn ($q) => $q->where('empresa_id', $empresaId))->ignore($ignoreId)],
            'direccion' => ['nullable', 'string', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
            'activo' => ['sometimes', 'boolean'],
        ]);
    }

    private function datosEquipo(Request $request, int $empresaId): array
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'integer'],
            'tipo' => ['required', 'string', 'max:120'],
            'marca' => ['nullable', 'string', 'max:120'],
            'modelo' => ['nullable', 'string', 'max:120'],
            'serie' => ['nullable', 'string', 'max:120'],
            'capacidad' => ['nullable', 'string', 'max:100'],
            'ubicacion' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
            'activo' => ['sometimes', 'boolean'],
        ]);
        $this->scoped('electrofrio_clientes', $empresaId, (int)$data['cliente_id']);
        return $data;
    }

    private function datosMaterial(Request $request, int $empresaId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:180', Rule::unique('electrofrio_materiales', 'nombre')->where(fn ($q) => $q->where('empresa_id', $empresaId))->ignore($ignoreId)],
            'unidad' => ['required', 'string', 'max:40'],
            'stock' => ['required', 'numeric', 'min:0'],
            'stock_minimo' => ['required', 'numeric', 'min:0'],
            'costo_unitario' => ['required', 'numeric', 'min:0'],
            'activo' => ['sometimes', 'boolean'],
        ]);
    }

    private function datosTecnico(Request $request, int $empresaId, ?int $ignoreId = null): array
    {
        $data=$request->validate([
            'usuario_id'=>['nullable','integer',Rule::unique('electrofrio_tecnicos','usuario_id')->where(fn($q)=>$q->where('empresa_id',$empresaId))->ignore($ignoreId)],
            'nombre'=>['required','string','max:180'],'telefono'=>['nullable','string','max:30'],'especialidad'=>['nullable','string','max:160'],'activo'=>['sometimes','boolean'],
        ]);
        if (!empty($data['usuario_id'])) {
            $member=DB::table('empresa_usuario')->where('empresa_id',$empresaId)->where('usuario_id',$data['usuario_id'])->where('activo',true)->exists();
            abort_unless($member,422,'La cuenta seleccionada no pertenece al equipo activo de este negocio.');
        }
        return $data;
    }

    private function datosOrden(Request $request, int $empresaId, ?int $orderId = null): array
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'integer'],
            'equipo_id' => ['nullable', 'integer'],
            'tecnico_id' => ['nullable', 'integer'],
            'fecha_cita' => ['required', 'date'],
            'hora_cita' => ['nullable', 'date_format:H:i'],
            'direccion_servicio' => ['required', 'string', 'max:255'],
            'referencia_ubicacion' => ['nullable', 'string', 'max:255'],
            'problema_reportado' => ['required', 'string', 'max:5000'],
            'prioridad' => ['required', Rule::in(['baja', 'normal', 'alta', 'urgente'])],
            'diagnostico' => ['nullable', 'string', 'max:8000'],
            'propuesta' => ['nullable', 'string', 'max:8000'],
            'trabajo_realizado' => ['nullable', 'string', 'max:8000'],
            'recomendaciones' => ['nullable', 'string', 'max:5000'],
            'costo_mano_obra' => ['nullable', 'numeric', 'min:0'],
            'descuento' => ['nullable', 'numeric', 'min:0'],
        ]);
        $this->scoped('electrofrio_clientes', $empresaId, (int)$data['cliente_id']);
        if (!empty($data['equipo_id'])) {
            $equipment = $this->scoped('electrofrio_equipos', $empresaId, (int)$data['equipo_id']);
            abort_unless((int)$equipment->cliente_id === (int)$data['cliente_id'], 422, 'El equipo no pertenece al cliente seleccionado.');
        }
        if (!empty($data['tecnico_id'])) $this->scoped('electrofrio_tecnicos', $empresaId, (int)$data['tecnico_id']);
        if ($orderId) $this->scoped('electrofrio_ordenes', $empresaId, $orderId);
        return $data;
    }

    private function scoped(string $table, int $empresaId, int $id): object
    {
        $item = DB::table($table)->where('empresa_id', $empresaId)->where('id', $id)->first();
        abort_unless($item, 404, 'El registro solicitado no existe en Electrofrío.');
        return $item;
    }

    private function ordenesQuery(int $empresaId): Builder
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
                't.nombre as tecnico_nombre'
            );
    }

    private function hydrateOrders($items, int $empresaId)
    {
        $ids = $items->pluck('id')->all();
        if (!$ids) return $items;

        $materials = $this->moduleEnabled('inventario')
            ? DB::table('electrofrio_orden_material as om')
                ->join('electrofrio_materiales as m', 'm.id', '=', 'om.material_id')
                ->where('om.empresa_id', $empresaId)->whereIn('om.orden_id', $ids)
                ->select('om.*', 'm.nombre as material_nombre', 'm.unidad as material_unidad')
                ->get()->groupBy('orden_id')
            : collect();
        $payments = $this->moduleEnabled('pagos')
            ? DB::table('electrofrio_pagos')->where('empresa_id', $empresaId)->whereIn('orden_id', $ids)->where('estado', 'pagado')->get()->groupBy('orden_id')
            : collect();

        return $items->map(function ($order) use ($materials, $payments) {
            $order->materiales = ($materials[$order->id] ?? collect())->values();
            $order->pagos = ($payments[$order->id] ?? collect())->values();
            $order->pagado = (float)$order->pagos->sum('monto');
            $order->saldo = max(0, (float)$order->total - $order->pagado);
            return $order;
        });
    }

    private function moduleEnabled(string $module): bool
    {
        return $this->activeModules === null || in_array($module, $this->activeModules, true);
    }

    private function findOrder(int $empresaId, int $id): object
    {
        $items = $this->hydrateOrders($this->ordenesQuery($empresaId)->where('o.id', $id)->get(), $empresaId);
        abort_if($items->isEmpty(), 404, 'La orden no existe.');
        return $items->first();
    }

    private function recalcularOrden(int $empresaId, int $id): void
    {
        $order = $this->scoped('electrofrio_ordenes', $empresaId, $id);
        $materials = (float) DB::table('electrofrio_orden_material')->where('empresa_id', $empresaId)->where('orden_id', $id)->sum('subtotal');
        $total = max(0, (float)$order->costo_mano_obra + $materials - (float)$order->descuento);
        $paid = (float) DB::table('electrofrio_pagos')->where('empresa_id', $empresaId)->where('orden_id', $id)->where('estado', 'pagado')->sum('monto');
        abort_if($total + 0.001 < $paid, 422, 'No puedes retirar materiales porque el total quedaría por debajo de lo ya pagado.');
        DB::table('electrofrio_ordenes')->where('id', $id)->update([
            'costo_materiales' => $materials,
            'total' => $total,
            'updated_at' => now(),
        ]);
    }
}
