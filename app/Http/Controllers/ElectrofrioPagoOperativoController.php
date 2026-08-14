<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Empresa};
use App\Services\{SubscriptionAccessService,TenantContext};
use App\Support\Audit;
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ElectrofrioPagoOperativoController extends Controller
{
    private const TYPES = ['anticipo','abono','saldo'];
    private const METHODS = ['efectivo','qr','transferencia','tarjeta','otro'];

    public function index(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresa=$this->empresa($request,$tenants);
        $data=$request->validate([
            'buscar'=>['nullable','string','max:160'],'tipo'=>['nullable',Rule::in(self::TYPES)],
            'metodo'=>['nullable',Rule::in(self::METHODS)],'estado'=>['nullable',Rule::in(['pagado','anulado'])],
            'orden_id'=>['nullable','integer','min:1'],'desde'=>['nullable','date'],'hasta'=>['nullable','date','after_or_equal:desde'],
        ]);
        $query=DB::table('electrofrio_pagos as p')->join('electrofrio_ordenes as o','o.id','=','p.orden_id')
            ->join('electrofrio_clientes as c','c.id','=','o.cliente_id')->where('p.empresa_id',$empresa->id)
            ->select('p.*','o.codigo as orden_codigo','o.total as orden_total','c.nombre as cliente_nombre');
        if(!empty($data['buscar'])){$term='%'.trim($data['buscar']).'%';$query->where(fn($q)=>$q->where('o.codigo','like',$term)->orWhere('c.nombre','like',$term)->orWhere('p.referencia','like',$term));}
        foreach(['tipo','metodo','estado','orden_id'] as $field)if(isset($data[$field])&&$data[$field]!=='')$query->where('p.'.$field,$data[$field]);
        if(!empty($data['desde']))$query->whereDate('p.pagado_at','>=',$data['desde']);
        if(!empty($data['hasta']))$query->whereDate('p.pagado_at','<=',$data['hasta']);
        $items=$query->orderByDesc('p.pagado_at')->orderByDesc('p.id')->limit(1000)->get();
        $paid=DB::table('electrofrio_pagos')->where('empresa_id',$empresa->id)->where('estado','pagado');
        return response()->json(['data'=>$items,'meta'=>['resumen'=>[
            'cobrado'=>(float)(clone $paid)->sum('monto'),
            'anticipos'=>(float)(clone $paid)->where('tipo','anticipo')->sum('monto'),
            'abonos'=>(float)(clone $paid)->where('tipo','abono')->sum('monto'),
            'saldos'=>(float)(clone $paid)->where('tipo','saldo')->sum('monto'),
            'anulados'=>DB::table('electrofrio_pagos')->where('empresa_id',$empresa->id)->where('estado','anulado')->count(),
        ]]]);
    }

    public function guardar(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresa=$this->empresa($request,$tenants);
        $data=$request->validate([
            'monto'=>['required','numeric','gt:0'],'tipo'=>['required',Rule::in(self::TYPES)],
            'metodo'=>['required',Rule::in(self::METHODS)],'referencia'=>['nullable','string','max:120'],
            'notas'=>['nullable','string','max:2000'],'idempotency_key'=>['required','string','min:16','max:64'],
        ]);
        $result=DB::transaction(function()use($empresa,$request,$data,$id):array{
            $order=DB::table('electrofrio_ordenes')->where('empresa_id',$empresa->id)->where('id',$id)->lockForUpdate()->first();
            abort_unless($order,404,'La orden solicitada no existe en este negocio.');
            $existing=DB::table('electrofrio_pagos')->where('empresa_id',$empresa->id)->where('idempotency_key',$data['idempotency_key'])->first();
            if($existing)return ['payment'=>$existing,'created'=>false];
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
            return ['payment'=>DB::table('electrofrio_pagos')->find($paymentId),'created'=>true];
        });
        if($result['created'])Audit::log($request,'electrofrio_pago_registrado',null,'Se registró un pago en una orden de Electrofrío.',['orden_id'=>$id,'pago_id'=>$result['payment']->id,'tipo'=>$result['payment']->tipo]);
        return response()->json(['data'=>$result['payment'],'message'=>$result['created']?'Pago registrado.':'El pago ya había sido procesado.'],$result['created']?201:200);
    }

    public function anular(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresa=$this->empresa($request,$tenants);$tenants->assertCanManage($request->user(),$empresa);
        $data=$request->validate(['motivo'=>['required','string','min:3','max:1000']]);
        $payment=DB::transaction(function()use($empresa,$request,$data,$id){
            $item=DB::table('electrofrio_pagos')->where('empresa_id',$empresa->id)->where('id',$id)->lockForUpdate()->first();
            abort_unless($item,404,'El pago solicitado no existe en este negocio.');
            if($item->estado==='anulado')return $item;
            DB::table('electrofrio_pagos')->where('id',$item->id)->update(['estado'=>'anulado','anulado_at'=>now(),'anulado_por'=>$request->user()->id,'motivo_anulacion'=>trim($data['motivo']),'updated_at'=>now()]);
            return DB::table('electrofrio_pagos')->find($item->id);
        });
        Audit::log($request,'electrofrio_pago_anulado',null,'Se anuló un pago de Electrofrío sin borrar su trazabilidad.',['pago_id'=>$payment->id,'orden_id'=>$payment->orden_id]);
        return response()->json(['data'=>$payment,'message'=>'Pago anulado.']);
    }

    private function empresa(Request $request,TenantContext $tenants):Empresa
    {
        $empresa=$tenants->resolve($request);$tenants->assertCanUse($request->user(),$empresa,'pagos');
        if(!$request->user()->isPlatformAdmin()){
            $app=Aplicacion::query()->where('empresa_id',$empresa->id)->whereHas('catalogo',fn($q)=>$q->where('clave','electrofrio'))->with('suscripcion')->latest('id')->first();
            abort_unless($app,404,'Este negocio no tiene Electrofrío asignado.');abort_unless((bool)$app->acceso_cliente,403,'Electrofrío todavía no fue entregado a este negocio.');
            abort_unless($app->estado==='activo',403,'El acceso a Electrofrío está suspendido.');app(SubscriptionAccessService::class)->assertCanUse($app);
        }
        return $empresa;
    }
}
