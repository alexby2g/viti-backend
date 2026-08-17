<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,ElectrofrioConfiguracion,Empresa};
use App\Services\{SubscriptionAccessService,TenantContext};
use App\Support\Audit;
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ElectrofrioPagoOperativoController extends Controller
{
    private const TYPES = ['anticipo','abono','saldo'];
    private const DEFAULT_METHODS = ['efectivo','qr','transferencia','tarjeta','otro'];

    public function index(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresa=$this->empresa($request,$tenants);
        $methods=$this->paymentMethods($empresa->id);
        $data=$request->validate([
            'buscar'=>['nullable','string','max:160'],'tipo'=>['nullable',Rule::in(self::TYPES)],
            'metodo'=>['nullable',Rule::in($methods)],'estado'=>['nullable',Rule::in(['pagado','anulado'])],
            'orden_id'=>['nullable','integer','min:1'],'desde'=>['nullable','date'],'hasta'=>['nullable','date','after_or_equal:desde'],
        ]);
        $query=DB::table('electrofrio_pagos as p')->join('electrofrio_ordenes as o','o.id','=','p.orden_id')
            ->join('electrofrio_clientes as c','c.id','=','o.cliente_id')->where('p.empresa_id',$empresa->id)
            ->select('p.*','o.codigo as orden_codigo','o.total as orden_total','o.estado_actual as servicio_estado','c.nombre as cliente_nombre');
        if(!empty($data['buscar'])){$term='%'.trim($data['buscar']).'%';$query->where(fn($q)=>$q->where('o.codigo','like',$term)->orWhere('c.nombre','like',$term)->orWhere('p.referencia','like',$term));}
        foreach(['tipo','metodo','estado','orden_id'] as $field)if(isset($data[$field])&&$data[$field]!=='')$query->where('p.'.$field,$data[$field]);
        if(!empty($data['desde']))$query->whereDate('p.pagado_at','>=',$data['desde']);
        if(!empty($data['hasta']))$query->whereDate('p.pagado_at','<=',$data['hasta']);
        $items=$query->orderByDesc('p.pagado_at')->orderByDesc('p.id')->limit(1000)->get();
        $paid=DB::table('electrofrio_pagos')->where('empresa_id',$empresa->id)->where('estado','pagado');
        return response()->json(['data'=>$items,'meta'=>[
            'metodos_pago'=>$methods,
            'resumen'=>[
                'cobrado'=>(float)(clone $paid)->sum('monto'),
                'anticipos'=>(float)(clone $paid)->where('tipo','anticipo')->sum('monto'),
                'abonos'=>(float)(clone $paid)->where('tipo','abono')->sum('monto'),
                'saldos'=>(float)(clone $paid)->where('tipo','saldo')->sum('monto'),
                'anulados'=>DB::table('electrofrio_pagos')->where('empresa_id',$empresa->id)->where('estado','anulado')->count(),
            ],
        ]]);
    }

    public function referencias(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresa=$this->empresa($request,$tenants);
        $items=DB::table('electrofrio_ordenes as o')
            ->join('electrofrio_clientes as c','c.id','=','o.cliente_id')
            ->leftJoin('electrofrio_pagos as p',function($join):void{
                $join->on('p.orden_id','=','o.id')
                    ->on('p.empresa_id','=','o.empresa_id')
                    ->where('p.estado','=','pagado');
            })
            ->where('o.empresa_id',$empresa->id)
            ->groupBy('o.id','o.codigo','o.total','o.etapa','o.estado_actual','o.fecha_cita','c.nombre')
            ->select(
                'o.id','o.codigo','o.total','o.etapa','o.estado_actual','o.fecha_cita','c.nombre as cliente_nombre',
                DB::raw('COALESCE(SUM(p.monto), 0) as pagado'),
                DB::raw('SUM(CASE WHEN p.id IS NOT NULL THEN 1 ELSE 0 END) as cantidad_pagos')
            )
            ->orderByDesc('o.id')->limit(500)->get()
            ->map(function($item){
                $item->pagado=(float)$item->pagado;
                $item->saldo=max(0,(float)$item->total-$item->pagado);
                $item->cantidad_pagos=(int)$item->cantidad_pagos;
                return $item;
            });

        return response()->json(['data'=>$items,'meta'=>['metodos_pago'=>$this->paymentMethods($empresa->id)]]);
    }

    public function guardar(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresa=$this->empresa($request,$tenants);
        $methods=$this->paymentMethods($empresa->id);
        $data=$request->validate([
            'monto'=>['required','numeric','gt:0'],'tipo'=>['required',Rule::in(self::TYPES)],
            'metodo'=>['required',Rule::in($methods)],'referencia'=>['nullable','string','max:120'],
            'notas'=>['nullable','string','max:2000'],'idempotency_key'=>['required','string','min:16','max:64'],
        ]);
        $result=DB::transaction(function()use($empresa,$request,$data,$id):array{
            $order=DB::table('electrofrio_ordenes')->where('empresa_id',$empresa->id)->where('id',$id)->lockForUpdate()->first();
            abort_unless($order,404,'La orden solicitada no existe en este negocio.');
            $existing=DB::table('electrofrio_pagos')->where('empresa_id',$empresa->id)->where('idempotency_key',$data['idempotency_key'])->first();
            if($existing){
                abort_if((int)$existing->orden_id!==$id,409,'La clave de esta operación ya fue utilizada en otra orden. Actualiza el formulario e inténtalo nuevamente.');
                return ['payment'=>$existing,'created'=>false,'service_finalized'=>false];
            }
            $payments=DB::table('electrofrio_pagos')->where('empresa_id',$empresa->id)->where('orden_id',$id)->where('estado','pagado')->lockForUpdate()->get();
            $paid=(float)$payments->sum('monto');$remaining=max(0,(float)$order->total-$paid);$amount=(float)$data['monto'];
            abort_if($remaining<=0.001,422,'La orden ya está pagada por completo.');
            abort_if($amount>$remaining+0.001,422,'El pago supera el saldo pendiente de la orden.');
            abort_if($data['tipo']==='anticipo'&&$payments->isNotEmpty(),422,'El anticipo debe ser el primer pago de la orden.');
            abort_if($data['tipo']==='saldo'&&abs($amount-$remaining)>0.01,422,'Un pago marcado como saldo debe cubrir exactamente el saldo pendiente.');
            $paymentId=DB::table('electrofrio_pagos')->insertGetId([
                'empresa_id'=>$empresa->id,'orden_id'=>$id,'monto'=>$amount,'tipo'=>$data['tipo'],'metodo'=>$data['metodo'],
                'referencia'=>isset($data['referencia'])?trim((string)$data['referencia'])?:null:null,
                'notas'=>isset($data['notas'])?trim((string)$data['notas'])?:null:null,'estado'=>'pagado',
                'registrado_por'=>$request->user()->id,'idempotency_key'=>$data['idempotency_key'],'pagado_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
            $newRemaining=max(0,$remaining-$amount);
            $finalized=$newRemaining<=0.001 && $this->finalizeAfterPayment($empresa->id,$order,$request->user()->id);
            return ['payment'=>DB::table('electrofrio_pagos')->find($paymentId),'created'=>true,'service_finalized'=>$finalized];
        });
        if($result['created'])Audit::log($request,'electrofrio_pago_registrado',null,'Se registró un pago en una orden del sistema de aire acondicionado.',['orden_id'=>$id,'pago_id'=>$result['payment']->id,'tipo'=>$result['payment']->tipo,'servicio_finalizado'=>$result['service_finalized']]);
        $message=$result['created']
            ? ($result['service_finalized']?'Pago registrado. El servicio quedó finalizado automáticamente porque ya no tiene saldo pendiente.':'Pago registrado.')
            : 'El pago ya había sido procesado.';
        return response()->json(['data'=>$result['payment'],'message'=>$message,'meta'=>['servicio_finalizado'=>$result['service_finalized']]],$result['created']?201:200);
    }

    public function anular(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresa=$this->empresa($request,$tenants);$tenants->assertCanManage($request->user(),$empresa);
        $data=$request->validate(['motivo'=>['required','string','min:3','max:1000']]);
        $result=DB::transaction(function()use($empresa,$request,$data,$id):array{
            $item=DB::table('electrofrio_pagos')->where('empresa_id',$empresa->id)->where('id',$id)->lockForUpdate()->first();
            abort_unless($item,404,'El pago solicitado no existe en este negocio.');
            if($item->estado==='anulado')return ['payment'=>$item,'changed'=>false,'service_reopened'=>false];
            DB::table('electrofrio_pagos')->where('id',$item->id)->update(['estado'=>'anulado','anulado_at'=>now(),'anulado_por'=>$request->user()->id,'motivo_anulacion'=>trim($data['motivo']),'updated_at'=>now()]);
            $reopened=$this->reopenAfterPaymentCancellation($empresa->id,(int)$item->orden_id,$request->user()->id,(int)$item->id);
            return ['payment'=>DB::table('electrofrio_pagos')->find($item->id),'changed'=>true,'service_reopened'=>$reopened];
        });
        if($result['changed'])Audit::log($request,'electrofrio_pago_anulado',null,'Se anuló un pago del sistema de aire acondicionado sin borrar su trazabilidad.',['pago_id'=>$result['payment']->id,'orden_id'=>$result['payment']->orden_id,'servicio_reabierto'=>$result['service_reopened']]);
        $message=$result['changed']
            ? ($result['service_reopened']?'Pago anulado. El servicio volvió a Pendiente de pago.':'Pago anulado.')
            : 'El pago ya estaba anulado.';
        return response()->json(['data'=>$result['payment'],'message'=>$message,'meta'=>['servicio_reabierto'=>$result['service_reopened']]]);
    }

    private function finalizeAfterPayment(int $empresaId,object $order,int $userId):bool
    {
        $current=$this->currentState($order);
        if(!in_array($current,['servicio_terminado','pendiente_pago'],true))return false;
        if(empty($order->servicio_terminado_at))return false;

        DB::table('electrofrio_ordenes')->where('empresa_id',$empresaId)->where('id',$order->id)->update([
            'estado_actual'=>'finalizado','estado_actualizado_at'=>now(),'etapa'=>'cerrada','finalizada_at'=>now(),'updated_at'=>now(),
        ]);
        $this->recordState($empresaId,(int)$order->id,$current,'finalizado','automatico','Saldo completado. El sistema finalizó el servicio automáticamente.',$userId);
        return true;
    }

    private function reopenAfterPaymentCancellation(int $empresaId,int $orderId,int $userId,int $paymentId):bool
    {
        $order=DB::table('electrofrio_ordenes')->where('empresa_id',$empresaId)->where('id',$orderId)->lockForUpdate()->first();
        if(!$order||$this->currentState($order)!=='finalizado'||empty($order->servicio_terminado_at))return false;
        $paid=(float)DB::table('electrofrio_pagos')->where('empresa_id',$empresaId)->where('orden_id',$orderId)->where('estado','pagado')->sum('monto');
        $remaining=max(0,(float)$order->total-$paid);
        if($remaining<=0.001)return false;

        DB::table('electrofrio_ordenes')->where('empresa_id',$empresaId)->where('id',$orderId)->update([
            'estado_actual'=>'pendiente_pago','estado_actualizado_at'=>now(),'etapa'=>'servicio','finalizada_at'=>null,'updated_at'=>now(),
        ]);
        $this->recordState($empresaId,$orderId,'finalizado','pendiente_pago','automatico',"Pago #{$paymentId} anulado. Se reabrió el saldo pendiente.",$userId);
        return true;
    }

    private function recordState(int $empresaId,int $orderId,?string $previous,string $state,string $type,string $note,int $userId):void
    {
        DB::table('electrofrio_orden_estados')->insert([
            'empresa_id'=>$empresaId,'orden_id'=>$orderId,'estado_anterior'=>$previous,'estado'=>$state,'tipo_cambio'=>$type,
            'observacion'=>$note,'cambiado_por'=>$userId,'cambiado_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function currentState(object $order):string
    {
        $state=trim((string)($order->estado_actual??''));
        if($state!=='')return $state;
        return match(true){
            $order->etapa==='cerrada'&&$order->decision_cliente==='rechazado'=>'no_aprobado',
            $order->etapa==='cerrada'=>'finalizado',$order->etapa==='servicio'=>'servicio_en_proceso',
            $order->etapa==='propuesta'=>'esperando_aprobacion',$order->etapa==='diagnostico'=>'diagnostico_realizado',
            default=>'cita_programada',
        };
    }

    private function paymentMethods(int $empresaId):array
    {
        $config=ElectrofrioConfiguracion::query()->where('empresa_id',$empresaId)->first();
        $methods=is_array($config?->metodos_pago)?$config->metodos_pago:self::DEFAULT_METHODS;
        $methods=array_values(array_unique(array_filter(array_map(fn($value)=>strtolower(trim((string)$value)),$methods))));
        return $methods?:self::DEFAULT_METHODS;
    }

    private function empresa(Request $request,TenantContext $tenants):Empresa
    {
        $empresa=$tenants->resolve($request);$tenants->assertCanUse($request->user(),$empresa,'pagos');
        if(!$request->user()->isPlatformAdmin()){
            $app=Aplicacion::query()->where('empresa_id',$empresa->id)->whereHas('catalogo',fn($q)=>$q->where('clave','electrofrio'))->with('suscripcion')->latest('id')->first();
            abort_unless($app,404,'Este negocio no tiene asignado el Sistema de Gestión de Servicios de Aire Acondicionado.');
            abort_unless((bool)$app->acceso_cliente,403,'El sistema todavía no fue entregado a este negocio.');
            abort_unless($app->estado==='activo',403,'El acceso al sistema está suspendido.');app(SubscriptionAccessService::class)->assertCanUse($app);
        }
        return $empresa;
    }
}
