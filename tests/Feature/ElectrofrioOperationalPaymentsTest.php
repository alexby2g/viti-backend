<?php

namespace Tests\Feature;

use App\Models\ElectrofrioConfiguracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioOperationalPaymentsTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_advance_and_balance_are_idempotent_and_never_overpay_order(): void
    {
        $tenant=$this->tenantWithPayments('PAY-A');$orderId=$this->order($tenant['company']->id,1000);
        $headers=['X-VITI-Empresa'=>(string)$tenant['company']->id];
        $advance=['monto'=>300,'tipo'=>'anticipo','metodo'=>'qr','referencia'=>'QR-001','idempotency_key'=>'pay-advance-00000001'];

        $first=$this->actingAs($tenant['user'])->postJson('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/pagos-operativos',$advance,$headers)
            ->assertCreated()->assertJsonPath('data.tipo','anticipo')->json('data');
        $this->actingAs($tenant['user'])->postJson('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/pagos-operativos',$advance,$headers)
            ->assertOk()->assertJsonPath('data.id',$first['id'])->assertJsonPath('message','El pago ya había sido procesado.');
        $this->assertSame(1,DB::table('electrofrio_pagos')->where('orden_id',$orderId)->count());

        $references=$this->actingAs($tenant['user'])->getJson('/api/v1/mi/apps/electrofrio/pagos-operativos/referencias',$headers)
            ->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$orderId)->json('data.0');
        $this->assertSame(700.0,(float)$references['saldo']);
        $this->assertSame(300.0,(float)$references['pagado']);

        $this->actingAs($tenant['user'])->postJson('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/pagos-operativos',[
            'monto'=>700,'tipo'=>'saldo','metodo'=>'transferencia','referencia'=>'TR-002','idempotency_key'=>'pay-balance-00000002',
        ],$headers)->assertCreated()->assertJsonPath('data.tipo','saldo');

        $this->actingAs($tenant['user'])->postJson('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/pagos-operativos',[
            'monto'=>1,'tipo'=>'abono','metodo'=>'efectivo','idempotency_key'=>'pay-extra-0000000003',
        ],$headers)->assertUnprocessable()->assertJsonPath('message','La orden ya está pagada por completo.');

        $this->actingAs($tenant['user'])->getJson('/api/v1/mi/apps/electrofrio/pagos-operativos',$headers)
            ->assertOk()->assertJsonPath('meta.resumen.cobrado',1000)->assertJsonPath('meta.resumen.anticipos',300)->assertJsonPath('meta.resumen.saldos',700);
    }

    public function test_payment_types_enforce_accounting_rules(): void
    {
        $tenant=$this->tenantWithPayments('PAY-B');$orderId=$this->order($tenant['company']->id,1000);$headers=['X-VITI-Empresa'=>(string)$tenant['company']->id];

        $this->actingAs($tenant['user'])->postJson('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/pagos-operativos',[
            'monto'=>1100,'tipo'=>'abono','metodo'=>'efectivo','idempotency_key'=>'pay-over-00000000001',
        ],$headers)->assertUnprocessable()->assertJsonPath('message','El pago supera el saldo pendiente de la orden.');

        $this->actingAs($tenant['user'])->postJson('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/pagos-operativos',[
            'monto'=>200,'tipo'=>'abono','metodo'=>'efectivo','idempotency_key'=>'pay-partial-00000001',
        ],$headers)->assertCreated();

        $this->actingAs($tenant['user'])->postJson('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/pagos-operativos',[
            'monto'=>100,'tipo'=>'anticipo','metodo'=>'efectivo','idempotency_key'=>'pay-late-advance-001',
        ],$headers)->assertUnprocessable()->assertJsonPath('message','El anticipo debe ser el primer pago de la orden.');

        $this->actingAs($tenant['user'])->postJson('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/pagos-operativos',[
            'monto'=>700,'tipo'=>'saldo','metodo'=>'qr','idempotency_key'=>'pay-wrong-balance-001',
        ],$headers)->assertUnprocessable()->assertJsonPath('message','Un pago marcado como saldo debe cubrir exactamente el saldo pendiente.');
    }

    public function test_payment_can_be_annulled_without_erasing_trace_and_is_tenant_isolated(): void
    {
        $first=$this->tenantWithPayments('PAY-C1');$second=$this->tenantWithPayments('PAY-C2');$orderId=$this->order($second['company']->id,500);
        $secondHeaders=['X-VITI-Empresa'=>(string)$second['company']->id];
        $payment=$this->actingAs($second['user'])->postJson('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/pagos-operativos',[
            'monto'=>200,'tipo'=>'anticipo','metodo'=>'qr','idempotency_key'=>'pay-cancel-000000001',
        ],$secondHeaders)->assertCreated()->json('data');

        $this->actingAs($first['user'])->postJson('/api/v1/mi/apps/electrofrio/pagos-operativos/'.$payment['id'].'/anular',['motivo'=>'No pertenece a mi empresa.'],[
            'X-VITI-Empresa'=>(string)$first['company']->id,
        ])->assertNotFound();

        $this->actingAs($second['user'])->postJson('/api/v1/mi/apps/electrofrio/pagos-operativos/'.$payment['id'].'/anular',['motivo'=>'Comprobante registrado por error.'],$secondHeaders)
            ->assertOk()->assertJsonPath('data.estado','anulado')->assertJsonPath('message','Pago anulado.');

        $this->assertDatabaseHas('electrofrio_pagos',['id'=>$payment['id'],'estado'=>'anulado','motivo_anulacion'=>'Comprobante registrado por error.']);
        $this->actingAs($second['user'])->getJson('/api/v1/mi/apps/electrofrio/ordenes-operativas/'.$orderId,[
            'X-VITI-Empresa'=>(string)$second['company']->id,
        ])->assertOk()->assertJsonPath('data.pagado',0)->assertJsonPath('data.saldo',500);
    }

    public function test_business_configuration_controls_allowed_payment_methods(): void
    {
        $tenant=$this->tenantWithPayments('PAY-CONFIG');
        $headers=['X-VITI-Empresa'=>(string)$tenant['company']->id];
        $orderId=$this->order($tenant['company']->id,600);

        ElectrofrioConfiguracion::create([
            'empresa_id'=>$tenant['company']->id,
            'metodos_pago'=>['qr','link_pago'],
            'actualizado_por'=>$tenant['user']->id,
        ]);

        $this->actingAs($tenant['user'])->getJson('/api/v1/mi/apps/electrofrio/pagos-operativos',$headers)
            ->assertOk()
            ->assertJsonPath('meta.metodos_pago.0','qr')
            ->assertJsonPath('meta.metodos_pago.1','link_pago');

        $this->actingAs($tenant['user'])->postJson('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/pagos-operativos',[
            'monto'=>100,'tipo'=>'anticipo','metodo'=>'link_pago','referencia'=>'LP-001','idempotency_key'=>'pay-custom-method-0001',
        ],$headers)->assertCreated()->assertJsonPath('data.metodo','link_pago');

        $this->actingAs($tenant['user'])->postJson('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/pagos-operativos',[
            'monto'=>50,'tipo'=>'abono','metodo'=>'efectivo','idempotency_key'=>'pay-forbidden-method01',
        ],$headers)->assertUnprocessable()->assertJsonValidationErrors('metodo');
    }

    private function tenantWithPayments(string $suffix):array
    {
        $tenant=$this->createTenant($suffix);
        $modules=$tenant['plan']->modulos??[];
        if(!in_array('pagos',$modules,true))$modules[]='pagos';
        $tenant['plan']->update(['modulos'=>array_values(array_unique($modules))]);
        return $tenant;
    }

    private function order(int $companyId,float $total):int
    {
        $now=now();$clientId=DB::table('electrofrio_clientes')->insertGetId(['empresa_id'=>$companyId,'nombre'=>'Cliente pagos','telefono'=>'7600'.str_pad((string)$companyId,4,'0',STR_PAD_LEFT),'activo'=>true,'created_at'=>$now,'updated_at'=>$now]);
        $id=DB::table('electrofrio_ordenes')->insertGetId([
            'empresa_id'=>$companyId,'codigo'=>'TMP-PAY-'.bin2hex(random_bytes(4)),'cliente_id'=>$clientId,'fecha_cita'=>'2026-08-22','hora_cita'=>'11:00',
            'direccion_servicio'=>'Av. Pagos 1','problema_reportado'=>'Servicio de prueba de pagos.','tipo_servicio'=>'Mantenimiento','prioridad'=>'normal',
            'etapa'=>'servicio','decision_cliente'=>'aceptado','diagnostico'=>'Diagnóstico realizado.','propuesta'=>'Propuesta aceptada.','costo_mano_obra'=>$total,
            'costo_materiales'=>0,'descuento'=>0,'total'=>$total,'created_at'=>$now,'updated_at'=>$now,
        ]);
        DB::table('electrofrio_ordenes')->where('id',$id)->update(['codigo'=>'EF-PAY-'.str_pad((string)$id,5,'0',STR_PAD_LEFT)]);return $id;
    }
}