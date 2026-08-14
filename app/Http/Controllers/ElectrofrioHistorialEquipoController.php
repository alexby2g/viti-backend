<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Empresa};
use App\Services\{SubscriptionAccessService,TenantContext};
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Support\Facades\DB;

class ElectrofrioHistorialEquipoController extends Controller
{
    public function index(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresa=$this->empresa($request,$tenants);
        $filters=$request->validate([
            'buscar'=>['nullable','string','max:160'],
            'cliente_id'=>['nullable','integer','min:1'],
            'solo_con_historial'=>['nullable','boolean'],
        ]);

        $query=DB::table('electrofrio_equipos as e')
            ->join('electrofrio_clientes as c','c.id','=','e.cliente_id')
            ->where('e.empresa_id',$empresa->id)
            ->select('e.*','c.nombre as cliente_nombre','c.telefono as cliente_telefono');
        if(!empty($filters['buscar'])){
            $term='%'.trim($filters['buscar']).'%';
            $query->where(fn($q)=>$q->where('c.nombre','like',$term)->orWhere('c.telefono','like',$term)
                ->orWhere('e.tipo','like',$term)->orWhere('e.marca','like',$term)->orWhere('e.modelo','like',$term)
                ->orWhere('e.serie','like',$term)->orWhere('e.capacidad','like',$term));
        }
        if(!empty($filters['cliente_id']))$query->where('e.cliente_id',(int)$filters['cliente_id']);
        $equipos=$query->orderByDesc('e.id')->limit(500)->get();
        $ids=$equipos->pluck('id')->map(fn($id)=>(int)$id)->all();
        if(!$ids)return response()->json(['data'=>[],'meta'=>['resumen'=>$this->emptySummary()]]);

        $orders=DB::table('electrofrio_ordenes')
            ->where('empresa_id',$empresa->id)->whereIn('equipo_id',$ids)
            ->select('equipo_id',DB::raw('COUNT(*) as ordenes_total'),
                DB::raw("SUM(CASE WHEN etapa = 'cerrada' THEN 1 ELSE 0 END) as servicios_cerrados"),
                DB::raw("SUM(CASE WHEN decision_cliente = 'rechazado' THEN 1 ELSE 0 END) as propuestas_rechazadas"),
                DB::raw('MAX(COALESCE(finalizada_at, created_at)) as ultima_at'),
                DB::raw('MAX(garantia_fin) as ultima_garantia_fin'))
            ->groupBy('equipo_id')->get()->keyBy('equipo_id');

        $sheets=DB::table('electrofrio_fichas_tecnicas')->where('empresa_id',$empresa->id)->whereIn('equipo_id',$ids)->get()->keyBy('equipo_id');
        $evidence=DB::table('electrofrio_evidencias as ev')->join('electrofrio_ordenes as o','o.id','=','ev.orden_id')
            ->where('ev.empresa_id',$empresa->id)->whereIn('o.equipo_id',$ids)
            ->select('o.equipo_id',DB::raw('COUNT(ev.id) as evidencias_total'))->groupBy('o.equipo_id')->get()->keyBy('equipo_id');

        $canPayments=$tenants->canUse($request->user(),$empresa,'pagos');
        $payments=$canPayments
            ?DB::table('electrofrio_pagos as p')->join('electrofrio_ordenes as o','o.id','=','p.orden_id')
                ->where('p.empresa_id',$empresa->id)->where('p.estado','pagado')->whereIn('o.equipo_id',$ids)
                ->select('o.equipo_id',DB::raw('COALESCE(SUM(p.monto),0) as pagado_total'))->groupBy('o.equipo_id')->get()->keyBy('equipo_id')
            :collect();
        $canWarranties=$tenants->canUse($request->user(),$empresa,'garantias');
        $today=now()->toDateString();

        $items=$equipos->map(function($equipment)use($orders,$sheets,$evidence,$payments,$canPayments,$canWarranties,$today){
            $stats=$orders[$equipment->id]??null;
            $equipment->ordenes_total=(int)($stats->ordenes_total??0);
            $equipment->servicios_cerrados=(int)($stats->servicios_cerrados??0);
            $equipment->propuestas_rechazadas=(int)($stats->propuestas_rechazadas??0);
            $equipment->ultima_at=$stats->ultima_at??null;
            $equipment->evidencias_total=(int)($evidence[$equipment->id]->evidencias_total??0);
            $equipment->pagado_total=$canPayments?(float)($payments[$equipment->id]->pagado_total??0):null;
            $equipment->garantia_fin=$canWarranties?($stats->ultima_garantia_fin??null):null;
            $equipment->garantia_vigente=$canWarranties&&$equipment->garantia_fin?($equipment->garantia_fin>=$today):null;
            $equipment->ficha_tecnica=$sheets[$equipment->id]??null;
            return $equipment;
        });
        if(($filters['solo_con_historial']??false))$items=$items->filter(fn($item)=>$item->ordenes_total>0)->values();

        return response()->json(['data'=>$items,'meta'=>['resumen'=>[
            'equipos'=>$items->count(),
            'con_historial'=>$items->where('ordenes_total','>',0)->count(),
            'servicios_cerrados'=>(int)$items->sum('servicios_cerrados'),
            'evidencias'=>(int)$items->sum('evidencias_total'),
            'pagado_total'=>$canPayments?(float)$items->sum('pagado_total'):null,
            'garantias_vigentes'=>$canWarranties?$items->where('garantia_vigente',true)->count():null,
        ]]]);
    }

    public function show(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresa=$this->empresa($request,$tenants);
        $equipo=DB::table('electrofrio_equipos as e')->join('electrofrio_clientes as c','c.id','=','e.cliente_id')
            ->where('e.empresa_id',$empresa->id)->where('e.id',$id)
            ->select('e.*','c.nombre as cliente_nombre','c.telefono as cliente_telefono','c.direccion as cliente_direccion')->first();
        abort_unless($equipo,404,'El equipo solicitado no existe en este negocio.');

        $equipo->ficha_tecnica=DB::table('electrofrio_fichas_tecnicas')->where('empresa_id',$empresa->id)->where('equipo_id',$id)->first();
        $orders=DB::table('electrofrio_ordenes as o')->leftJoin('electrofrio_tecnicos as t','t.id','=','o.tecnico_id')
            ->where('o.empresa_id',$empresa->id)->where('o.equipo_id',$id)
            ->select('o.*','t.nombre as tecnico_nombre')->orderByDesc('o.fecha_cita')->orderByDesc('o.id')->get();
        $orderIds=$orders->pluck('id')->map(fn($value)=>(int)$value)->all();

        $canInventory=$tenants->canUse($request->user(),$empresa,'inventario');
        $canPayments=$tenants->canUse($request->user(),$empresa,'pagos');
        $canWarranties=$tenants->canUse($request->user(),$empresa,'garantias');
        $materials=$canInventory&&$orderIds
            ?DB::table('electrofrio_orden_material as om')->join('electrofrio_materiales as m','m.id','=','om.material_id')
                ->where('om.empresa_id',$empresa->id)->whereIn('om.orden_id',$orderIds)
                ->select('om.*','m.nombre as material_nombre','m.unidad as material_unidad')->get()->groupBy('orden_id')
            :collect();
        $payments=$canPayments&&$orderIds
            ?DB::table('electrofrio_pagos')->where('empresa_id',$empresa->id)->whereIn('orden_id',$orderIds)->orderByDesc('pagado_at')->get()->groupBy('orden_id')
            :collect();
        $evidence=$orderIds
            ?DB::table('electrofrio_evidencias')->where('empresa_id',$empresa->id)->whereIn('orden_id',$orderIds)->orderByDesc('id')->get()->groupBy('orden_id')
            :collect();

        $orders=$orders->map(function($order)use($materials,$payments,$evidence,$canInventory,$canPayments,$canWarranties){
            $order->materiales=$canInventory?($materials[$order->id]??collect())->values():null;
            $order->pagos=$canPayments?($payments[$order->id]??collect())->values():null;
            $order->evidencias=($evidence[$order->id]??collect())->values();
            $order->pagado=$canPayments?(float)collect($order->pagos)->where('estado','pagado')->sum('monto'):null;
            $order->saldo=$canPayments?max(0,(float)$order->total-(float)$order->pagado):null;
            if(!$canWarranties){$order->garantia_dias=null;$order->garantia_inicio=null;$order->garantia_fin=null;$order->condiciones_garantia=null;}
            return $order;
        });

        return response()->json(['data'=>[
            'equipo'=>$equipo,
            'ordenes'=>$orders,
            'resumen'=>[
                'ordenes'=>$orders->count(),
                'servicios_cerrados'=>$orders->where('etapa','cerrada')->count(),
                'propuestas_rechazadas'=>$orders->where('decision_cliente','rechazado')->count(),
                'evidencias'=>(int)$orders->sum(fn($order)=>collect($order->evidencias)->count()),
                'pagado_total'=>$canPayments?(float)$orders->sum('pagado'):null,
            ],
        ]]);
    }

    private function empresa(Request $request,TenantContext $tenants):Empresa
    {
        $empresa=$tenants->resolve($request);
        $tenants->assertCanUse($request->user(),$empresa,'historial');
        if(!$request->user()->isPlatformAdmin()){
            $app=Aplicacion::query()->where('empresa_id',$empresa->id)->whereHas('catalogo',fn($q)=>$q->where('clave','electrofrio'))->with('suscripcion')->latest('id')->first();
            abort_unless($app,404,'Este negocio no tiene Electrofrío asignado.');
            abort_unless((bool)$app->acceso_cliente,403,'Electrofrío todavía no fue entregado a este negocio.');
            abort_unless($app->estado==='activo',403,'El acceso a Electrofrío está suspendido.');
            app(SubscriptionAccessService::class)->assertCanUse($app);
        }
        return $empresa;
    }

    private function emptySummary():array
    {
        return ['equipos'=>0,'con_historial'=>0,'servicios_cerrados'=>0,'evidencias'=>0,'pagado_total'=>null,'garantias_vigentes'=>null];
    }
}
