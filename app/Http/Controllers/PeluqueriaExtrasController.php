<?php

namespace App\Http\Controllers;

use App\Models\{PeluqueriaAtencion,PeluqueriaProducto,PeluqueriaProductoMovimiento,PeluqueriaServicio};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PeluqueriaExtrasController extends Controller
{
    private function empresaId(Request $request): int
    {
        $data = $request->validate(['empresa_id'=>['required','integer','exists:empresas,id']]);
        return (int) $data['empresa_id'];
    }

    private function producto(int $empresaId, int $id): PeluqueriaProducto
    {
        return PeluqueriaProducto::query()->where('empresa_id',$empresaId)->findOrFail($id);
    }

    private function combo(int $empresaId, int $id): PeluqueriaServicio
    {
        return PeluqueriaServicio::query()->where('empresa_id',$empresaId)->where('tipo','combo')->findOrFail($id);
    }

    public function combos(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request);
        return response()->json(['data'=>PeluqueriaServicio::query()
            ->where('empresa_id',$empresaId)->where('tipo','combo')
            ->with('componentes:id,nombre,precio,duracion_minutos')
            ->orderBy('nombre')->get()]);
    }

    public function guardarCombo(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request);
        $data = $this->validarCombo($request,$empresaId);
        $componentes = $data['servicio_ids'] ?? [];
        unset($data['servicio_ids']);

        $combo = DB::transaction(function () use ($empresaId,$data,$componentes): PeluqueriaServicio {
            $item = PeluqueriaServicio::create($data + ['empresa_id'=>$empresaId,'tipo'=>'combo']);
            $item->componentes()->sync($componentes);
            return $item;
        });

        return response()->json(['data'=>$combo->load('componentes:id,nombre,precio,duracion_minutos')],201);
    }

    public function actualizarCombo(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request);
        $combo = $this->combo($empresaId,$id);
        $data = $this->validarCombo($request,$empresaId);
        $componentes = $data['servicio_ids'] ?? [];
        unset($data['servicio_ids']);

        DB::transaction(function () use ($combo,$data,$componentes): void {
            $combo->update($data);
            $combo->componentes()->sync($componentes);
        });

        return response()->json(['data'=>$combo->fresh()->load('componentes:id,nombre,precio,duracion_minutos')]);
    }

    public function eliminarCombo(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request);
        $combo = $this->combo($empresaId,$id);
        abort_if($combo->citas()->exists() || $combo->atenciones()->exists(),422,'Este combo ya tiene historial. Puedes desactivarlo en lugar de eliminarlo.');
        $combo->delete();
        return response()->json(status:204);
    }

    private function validarCombo(Request $request, int $empresaId): array
    {
        $data = $request->validate([
            'nombre'=>['required','string','max:160'],
            'categoria'=>['nullable','string','max:100'],
            'duracion_minutos'=>['required','integer','min:5','max:720'],
            'precio'=>['required','numeric','min:0'],
            'descripcion'=>['nullable','string','max:3000'],
            'activo'=>['sometimes','boolean'],
            'servicio_ids'=>['nullable','array'],
            'servicio_ids.*'=>['integer','distinct'],
        ]);

        $ids = collect($data['servicio_ids'] ?? [])->map(fn($id)=>(int)$id)->values();
        if ($ids->isNotEmpty()) {
            $count = PeluqueriaServicio::query()->where('empresa_id',$empresaId)->where('tipo','servicio')->whereIn('id',$ids)->count();
            abort_unless($count === $ids->count(),422,'Uno de los servicios seleccionados no pertenece a este negocio.');
        }
        return $data;
    }

    public function productos(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request);
        $query = PeluqueriaProducto::query()->where('empresa_id',$empresaId)->orderBy('nombre');
        if ($request->filled('buscar')) {
            $term = '%'.$request->string('buscar').'%';
            $query->where(fn($q)=>$q->where('nombre','like',$term)->orWhere('categoria','like',$term));
        }
        return response()->json(['data'=>$query->get()]);
    }

    public function guardarProducto(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request);
        $data = $this->validarProducto($request);
        return response()->json(['data'=>PeluqueriaProducto::create($data+['empresa_id'=>$empresaId])],201);
    }

    public function actualizarProducto(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request);
        $producto = $this->producto($empresaId,$id);
        $producto->update($this->validarProducto($request));
        return response()->json(['data'=>$producto->fresh()]);
    }

    public function eliminarProducto(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request);
        $producto = $this->producto($empresaId,$id);
        abort_if($producto->movimientos()->exists(),422,'El producto tiene movimientos y no puede eliminarse. Puedes desactivarlo.');
        $producto->delete();
        return response()->json(status:204);
    }

    private function validarProducto(Request $request): array
    {
        return $request->validate([
            'nombre'=>['required','string','max:180'],
            'categoria'=>['nullable','string','max:100'],
            'tipo'=>['required',Rule::in(['uso_interno','venta','mixto'])],
            'unidad'=>['required','string','max:30'],
            'stock'=>['nullable','numeric','min:0'],
            'stock_minimo'=>['nullable','numeric','min:0'],
            'costo'=>['nullable','numeric','min:0'],
            'precio_venta'=>['nullable','numeric','min:0'],
            'descripcion'=>['nullable','string','max:3000'],
            'activo'=>['sometimes','boolean'],
        ]);
    }

    public function movimientos(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request);
        return response()->json(['data'=>PeluqueriaProductoMovimiento::query()
            ->where('empresa_id',$empresaId)->with('producto:id,nombre,unidad')
            ->latest('registrado_at')->limit(200)->get()]);
    }

    public function registrarMovimiento(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request);
        $data = $request->validate([
            'producto_id'=>['required','integer'],
            'atencion_id'=>['nullable','integer'],
            'tipo'=>['required',Rule::in(['entrada','uso','venta','ajuste'])],
            'cantidad'=>['required','numeric','not_in:0'],
            'costo_unitario'=>['nullable','numeric','min:0'],
            'precio_unitario'=>['nullable','numeric','min:0'],
            'motivo'=>['nullable','string','max:255'],
        ]);

        $producto = $this->producto($empresaId,(int)$data['producto_id']);
        $cantidad = (float) $data['cantidad'];
        if (in_array($data['tipo'],['entrada','uso','venta'],true)) {
            abort_if($cantidad <= 0,422,'La cantidad debe ser mayor a cero.');
        }
        if (!empty($data['atencion_id'])) {
            abort_unless(PeluqueriaAtencion::query()->where('empresa_id',$empresaId)->whereKey($data['atencion_id'])->exists(),422,'La atención seleccionada no pertenece a este negocio.');
        }

        $delta = match($data['tipo']) {
            'entrada' => $cantidad,
            'uso','venta' => -$cantidad,
            default => $cantidad,
        };
        $nuevoStock = (float)$producto->stock + $delta;
        abort_if($nuevoStock < 0,422,'No hay stock suficiente para registrar este movimiento.');

        $movimiento = DB::transaction(function () use ($empresaId,$producto,$data,$cantidad,$nuevoStock): PeluqueriaProductoMovimiento {
            $producto->update(['stock'=>$nuevoStock]);
            return PeluqueriaProductoMovimiento::create([
                'empresa_id'=>$empresaId,
                'producto_id'=>$producto->id,
                'atencion_id'=>$data['atencion_id'] ?? null,
                'tipo'=>$data['tipo'],
                'cantidad'=>$cantidad,
                'costo_unitario'=>$data['costo_unitario'] ?? $producto->costo,
                'precio_unitario'=>$data['precio_unitario'] ?? ($data['tipo']==='venta' ? $producto->precio_venta : null),
                'motivo'=>$data['motivo'] ?? null,
                'registrado_at'=>now(),
            ]);
        });

        return response()->json(['data'=>$movimiento->load('producto:id,nombre,unidad'),'producto'=>$producto->fresh()],201);
    }

    public function reportes(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request);
        $data = $request->validate(['desde'=>['nullable','date'],'hasta'=>['nullable','date','after_or_equal:desde']]);
        $desde = isset($data['desde']) ? now()->parse($data['desde'])->startOfDay() : now()->startOfMonth();
        $hasta = isset($data['hasta']) ? now()->parse($data['hasta'])->endOfDay() : now()->endOfMonth();

        $ingresos = (float) DB::table('peluqueria_pagos')->where('empresa_id',$empresaId)->whereBetween('pagado_at',[$desde,$hasta])->sum('monto');
        $atenciones = DB::table('peluqueria_atenciones')->where('empresa_id',$empresaId)->where('estado','finalizada')->whereBetween('finalizada_at',[$desde,$hasta])->count();
        $clientesNuevos = DB::table('peluqueria_clientes')->where('empresa_id',$empresaId)->whereBetween('created_at',[$desde,$hasta])->count();
        $clientesAtendidos = DB::table('peluqueria_atenciones')->where('empresa_id',$empresaId)->where('estado','finalizada')->whereBetween('finalizada_at',[$desde,$hasta])->distinct('cliente_id')->count('cliente_id');
        $recurrentes = DB::table('peluqueria_atenciones')->where('empresa_id',$empresaId)->where('estado','finalizada')->whereBetween('finalizada_at',[$desde,$hasta])->select('cliente_id')->groupBy('cliente_id')->havingRaw('COUNT(*) > 1')->get()->count();

        $topServicios = DB::table('peluqueria_atenciones as a')
            ->join('peluqueria_servicios as s','s.id','=','a.servicio_id')
            ->where('a.empresa_id',$empresaId)->where('a.estado','finalizada')->whereBetween('a.finalizada_at',[$desde,$hasta])->where('s.tipo','servicio')
            ->groupBy('s.id','s.nombre')->selectRaw('s.id, s.nombre, COUNT(*) as cantidad, COALESCE(SUM(a.total),0) as total')
            ->orderByDesc('cantidad')->limit(5)->get();

        $topCombos = DB::table('peluqueria_atenciones as a')
            ->join('peluqueria_servicios as s','s.id','=','a.servicio_id')
            ->where('a.empresa_id',$empresaId)->where('a.estado','finalizada')->whereBetween('a.finalizada_at',[$desde,$hasta])->where('s.tipo','combo')
            ->groupBy('s.id','s.nombre')->selectRaw('s.id, s.nombre, COUNT(*) as cantidad, COALESCE(SUM(a.total),0) as total')
            ->orderByDesc('cantidad')->limit(5)->get();

        $topProductos = DB::table('peluqueria_producto_movimientos as m')
            ->join('peluqueria_productos as p','p.id','=','m.producto_id')
            ->where('m.empresa_id',$empresaId)->where('m.tipo','venta')->whereBetween('m.registrado_at',[$desde,$hasta])
            ->groupBy('p.id','p.nombre')->selectRaw('p.id, p.nombre, SUM(m.cantidad) as cantidad, COALESCE(SUM(m.cantidad * COALESCE(m.precio_unitario,0)),0) as total')
            ->orderByDesc('cantidad')->limit(5)->get();

        $topPersonal = DB::table('peluqueria_atenciones as a')
            ->join('peluqueria_personal as p','p.id','=','a.personal_id')
            ->where('a.empresa_id',$empresaId)->where('a.estado','finalizada')->whereBetween('a.finalizada_at',[$desde,$hasta])
            ->groupBy('p.id','p.nombre')->selectRaw('p.id, p.nombre, COUNT(*) as atenciones, COALESCE(SUM(a.total),0) as total')
            ->orderByDesc('total')->limit(5)->get();

        $stockBajo = PeluqueriaProducto::query()->where('empresa_id',$empresaId)->where('activo',true)->whereColumn('stock','<=','stock_minimo')->count();

        return response()->json(['data'=>[
            'periodo'=>['desde'=>$desde->toDateString(),'hasta'=>$hasta->toDateString()],
            'resumen'=>[
                'ingresos'=>$ingresos,
                'atenciones'=>$atenciones,
                'ticket_promedio'=>$atenciones > 0 ? round($ingresos/$atenciones,2) : 0,
                'clientes_nuevos'=>$clientesNuevos,
                'clientes_atendidos'=>$clientesAtendidos,
                'clientes_recurrentes'=>$recurrentes,
                'porcentaje_recurrentes'=>$clientesAtendidos > 0 ? round(($recurrentes/$clientesAtendidos)*100,1) : 0,
                'stock_bajo'=>$stockBajo,
            ],
            'top_servicios'=>$topServicios,
            'top_combos'=>$topCombos,
            'top_productos'=>$topProductos,
            'top_personal'=>$topPersonal,
        ]]);
    }
}
