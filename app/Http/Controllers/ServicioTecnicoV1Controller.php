<?php

namespace App\Http\Controllers;

use App\Models\Aplicacion;
use App\Services\TenantContext;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\{JsonResponse,Request,Response};
use Illuminate\Support\Facades\{DB,Storage};

class ServicioTecnicoV1Controller extends Controller
{
    private const STATE_LABELS = [
        'recibido'=>'Recibido',
        'diagnostico'=>'En diagnóstico',
        'esperando_aprobacion'=>'Esperando aprobación',
        'reparacion'=>'En reparación',
        'pruebas'=>'En pruebas',
        'listo_entrega'=>'Listo para entregar',
        'entregado'=>'Entregado',
        'sin_reparacion'=>'Sin reparación',
    ];

    public function estado(Request $request, TenantContext $tenants): JsonResponse
    {
        [$empresa,$app] = $this->context($request,$tenants,'inicio');
        $modules = $tenants->effectiveModules($request->user(),$empresa) ?? [];

        return response()->json(['data'=>[
            'aplicacion'=>[
                'id'=>$app->id,
                'nombre'=>$app->nombre,
                'version'=>$app->version,
                'entorno'=>$app->entorno,
                'estado'=>$app->estado,
                'acceso_cliente'=>(bool)$app->acceso_cliente,
                'entregado_at'=>$app->entregado_at,
            ],
            'empresa'=>['id'=>$empresa->id,'nombre_comercial'=>$empresa->nombre_comercial],
            'rol'=>$tenants->role($request->user(),$empresa),
            'modulos'=>$modules,
            'puede_administrar'=>$tenants->canManage($request->user(),$empresa),
        ]]);
    }

    public function referencias(Request $request, TenantContext $tenants): JsonResponse
    {
        [$empresa] = $this->context($request,$tenants,'ordenes');
        $empresaId = (int)$empresa->id;

        return response()->json(['data'=>[
            'clientes'=>DB::table('servicio_tecnico_clientes')->where('empresa_id',$empresaId)->where('activo',true)
                ->select('id','nombre','telefono')->orderBy('nombre')->limit(500)->get(),
            'equipos'=>DB::table('servicio_tecnico_equipos')->where('empresa_id',$empresaId)->where('activo',true)
                ->select('id','cliente_id','tipo','marca','modelo','serie')->orderByDesc('id')->limit(500)->get(),
            'tecnicos'=>DB::table('servicio_tecnico_tecnicos')->where('empresa_id',$empresaId)->where('activo',true)
                ->select('id','usuario_id','nombre','especialidad')->orderBy('nombre')->limit(200)->get(),
        ]]);
    }

    public function eliminarOrden(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        [$empresa] = $this->context($request,$tenants,'ordenes');
        $empresaId = (int)$empresa->id;
        $orden = $this->scoped('servicio_tecnico_ordenes',$empresaId,$id);

        abort_unless($orden->estado === 'recibido',422,'Solo puede eliminarse una orden recién recibida que todavía no tenga trabajo técnico.');
        abort_if(
            filled($orden->diagnostico) || filled($orden->propuesta) || filled($orden->trabajo_realizado) ||
            DB::table('servicio_tecnico_pagos')->where('empresa_id',$empresaId)->where('orden_id',$id)->exists() ||
            DB::table('servicio_tecnico_evidencias')->where('empresa_id',$empresaId)->where('orden_id',$id)->exists(),
            422,
            'La orden ya tiene trazabilidad y debe conservarse en el historial.'
        );

        DB::table('servicio_tecnico_ordenes')->where('id',$id)->delete();
        Audit::log($request,'servicio_tecnico_orden_eliminada',null,'Se eliminó una orden recién recibida sin movimientos ni historial técnico.');
        return response()->json(status:204);
    }

    public function cambiarEstado(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        [$empresa] = $this->context($request,$tenants,'ordenes');
        $empresaId = (int)$empresa->id;
        $orden = $this->scoped('servicio_tecnico_ordenes',$empresaId,$id);
        $data = $request->validate(['estado'=>['required','string']]);
        $target = $data['estado'];

        abort_if(in_array($orden->estado,['entregado','sin_reparacion'],true),422,'La orden ya está cerrada.');
        $allowed = [
            'pruebas'=>['listo_entrega'],
            'listo_entrega'=>['entregado'],
        ];
        abort_unless(in_array($target,$allowed[$orden->estado] ?? [],true),422,'Ese cambio de estado no corresponde al flujo actual de la reparación.');
        abort_unless($orden->decision_cliente === 'aceptado',422,'El cliente debe aceptar la propuesta antes de continuar.');
        if ($target === 'listo_entrega') abort_if(empty($orden->trabajo_realizado),422,'Registra el trabajo realizado antes de marcar el equipo como listo.');

        DB::table('servicio_tecnico_ordenes')->where('id',$id)->update([
            'estado'=>$target,
            'finalizada_at'=>$target === 'entregado' ? now() : null,
            'updated_at'=>now(),
        ]);
        Audit::log($request,'servicio_tecnico_estado_actualizado',null,'La orden avanzó a '.$target.'.');
        return response()->json(['data'=>$this->findOrder($empresaId,$id)]);
    }

    public function eliminarEvidencia(Request $request, TenantContext $tenants, int $evidencia): JsonResponse
    {
        [$empresa] = $this->context($request,$tenants,'ordenes');
        $empresaId = (int)$empresa->id;
        $item = DB::table('servicio_tecnico_evidencias')->where('empresa_id',$empresaId)->where('id',$evidencia)->first();
        abort_unless($item,404,'La evidencia no existe.');
        $orden = $this->scoped('servicio_tecnico_ordenes',$empresaId,(int)$item->orden_id);
        abort_if(in_array($orden->estado,['entregado','sin_reparacion'],true),422,'Las evidencias de una orden cerrada forman parte del historial y ya no pueden eliminarse.');

        Storage::disk('private_uploads')->delete($item->ruta);
        DB::table('servicio_tecnico_evidencias')->where('id',$item->id)->delete();
        Audit::log($request,'servicio_tecnico_evidencia_eliminada',null,'Se eliminó una evidencia de una orden todavía abierta.');
        return response()->json(status:204);
    }

    public function reporteOrden(Request $request, TenantContext $tenants, int $id): Response
    {
        [$empresa] = $this->context($request,$tenants,'historial');
        $empresaId = (int)$empresa->id;
        $orden = $this->findOrder($empresaId,$id);
        $estado = self::STATE_LABELS[$orden->estado] ?? ucfirst(str_replace('_',' ',$orden->estado));

        return Pdf::loadView('reports.servicio-tecnico-orden',compact('empresa','orden','estado'))
            ->setPaper('a4')
            ->download($orden->codigo.'-orden-servicio.pdf');
    }

    public function reporteResumen(Request $request, TenantContext $tenants): Response
    {
        [$empresa] = $this->context($request,$tenants,'historial');
        $empresaId = (int)$empresa->id;
        $data = $request->validate([
            'desde'=>['nullable','date'],
            'hasta'=>['nullable','date','after_or_equal:desde'],
        ]);
        $desde = $data['desde'] ?? null;
        $hasta = $data['hasta'] ?? null;

        $query = $this->orderQuery($empresaId);
        if ($desde) $query->whereDate('o.fecha_recepcion','>=',$desde);
        if ($hasta) $query->whereDate('o.fecha_recepcion','<=',$hasta);
        $ordenes = $this->hydrateOrders($query->orderByDesc('o.fecha_recepcion')->orderByDesc('o.id')->limit(1000)->get(),$empresaId);

        $pagosQuery = DB::table('servicio_tecnico_pagos as p')
            ->join('servicio_tecnico_ordenes as o','o.id','=','p.orden_id')
            ->join('servicio_tecnico_clientes as c','c.id','=','o.cliente_id')
            ->where('p.empresa_id',$empresaId)
            ->where('p.estado','pagado');
        if ($desde) $pagosQuery->whereDate('p.pagado_at','>=',$desde);
        if ($hasta) $pagosQuery->whereDate('p.pagado_at','<=',$hasta);
        $pagos = $pagosQuery->select('p.*','o.codigo as orden_codigo','c.nombre as cliente_nombre')->orderByDesc('p.pagado_at')->limit(1500)->get();

        $facturado = (float)$ordenes->sum('total');
        $cobrado = (float)$pagos->sum('monto');
        $estadisticas = [
            'ordenes'=>$ordenes->count(),
            'entregadas'=>$ordenes->where('estado','entregado')->count(),
            'sin_reparacion'=>$ordenes->where('estado','sin_reparacion')->count(),
            'facturado'=>$facturado,
            'cobrado'=>$cobrado,
            'saldo'=>max(0,$facturado-(float)$ordenes->sum('pagado')),
        ];
        $estados = self::STATE_LABELS;

        return Pdf::loadView('reports.servicio-tecnico-resumen',compact('empresa','ordenes','pagos','estadisticas','estados','desde','hasta'))
            ->setPaper('a4','landscape')
            ->download('servicio-tecnico-reporte-'.now()->format('Ymd').'.pdf');
    }

    private function context(Request $request, TenantContext $tenants, string $module): array
    {
        $empresa = $tenants->resolve($request);
        $app = Aplicacion::query()
            ->where('empresa_id',$empresa->id)
            ->whereHas('catalogo',fn($q)=>$q->where('clave','servicio-tecnico'))
            ->whereNotIn('estado',['retirado'])
            ->with(['catalogo','suscripcion'])
            ->latest('id')
            ->first();
        abort_unless($app,404,'Este negocio no tiene Servicio Técnico VITI habilitado.');
        $tenants->assertModule($empresa,$module);
        if (!$request->user()->isPlatformAdmin()) $tenants->assertCanUse($request->user(),$empresa,$module);
        return [$empresa,$app];
    }

    private function scoped(string $table, int $empresaId, int $id): object
    {
        $item = DB::table($table)->where('empresa_id',$empresaId)->where('id',$id)->first();
        abort_unless($item,404,'El registro solicitado no existe en este Servicio Técnico.');
        return $item;
    }

    private function orderQuery(int $empresaId): Builder
    {
        return DB::table('servicio_tecnico_ordenes as o')
            ->join('servicio_tecnico_clientes as c','c.id','=','o.cliente_id')
            ->leftJoin('servicio_tecnico_equipos as e','e.id','=','o.equipo_id')
            ->leftJoin('servicio_tecnico_tecnicos as t','t.id','=','o.tecnico_id')
            ->where('o.empresa_id',$empresaId)
            ->select(
                'o.*','c.nombre as cliente_nombre','c.telefono as cliente_telefono',
                'e.tipo as equipo_tipo','e.marca as equipo_marca','e.modelo as equipo_modelo','e.serie as equipo_serie',
                't.nombre as tecnico_nombre'
            );
    }

    private function hydrateOrders($items, int $empresaId)
    {
        $ids = $items->pluck('id')->all();
        if (!$ids) return $items;
        $payments = DB::table('servicio_tecnico_pagos')->where('empresa_id',$empresaId)->whereIn('orden_id',$ids)->where('estado','pagado')->get()->groupBy('orden_id');
        $evidence = DB::table('servicio_tecnico_evidencias')->where('empresa_id',$empresaId)->whereIn('orden_id',$ids)->orderByDesc('id')->get()->groupBy('orden_id');
        return $items->map(function($order) use ($payments,$evidence) {
            $order->pagos = ($payments[$order->id] ?? collect())->values();
            $order->pagado = (float)$order->pagos->sum('monto');
            $order->saldo = max(0,(float)$order->total-$order->pagado);
            $order->evidencias = ($evidence[$order->id] ?? collect())->values();
            return $order;
        });
    }

    private function findOrder(int $empresaId, int $id): object
    {
        $items = $this->hydrateOrders($this->orderQuery($empresaId)->where('o.id',$id)->get(),$empresaId);
        abort_if($items->isEmpty(),404,'La orden no existe.');
        return $items->first();
    }
}
