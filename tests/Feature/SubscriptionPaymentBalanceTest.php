<?php

namespace Tests\Feature;

use App\Models\SuscripcionPago;
use App\Services\SubscriptionAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class SubscriptionPaymentBalanceTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_first_payment_balance_decreases_with_confirmed_installments(): void
    {
        $tenant = $this->createTenant('FIRST-BALANCE');
        $subscription = $this->subscription($tenant, '2026-08-14', 3);
        $subscription->update([
            'primer_cobro_monto'=>129,
            'primer_cobro_desde'=>'2026-08-15',
            'primer_cobro_hasta'=>'2026-09-14',
            'primer_cobro_pagado'=>false,
            'estado'=>'suspendida',
        ]);

        SuscripcionPago::create([
            'suscripcion_id'=>$subscription->id,
            'empresa_id'=>$tenant['company']->id,
            'monto'=>50,
            'metodo'=>'qr',
            'fecha_pago'=>'2026-08-15',
            'estado_revision'=>'confirmado',
            'origen'=>'cliente',
        ]);

        $status = app(SubscriptionAccessService::class)->statusFor($tenant['app']->fresh());

        $this->assertSame(129.0, $status['primer_cobro_monto']);
        $this->assertSame(50.0, $status['primer_cobro_confirmado']);
        $this->assertSame(79.0, $status['primer_cobro_restante']);
        $this->assertFalse($status['primer_cobro_completo']);
        $this->assertFalse($status['primer_cobro_pagado']);
        $this->assertSame('suspendida', $status['estado']);
        $this->assertFalse($status['puede_usar']);
    }

    public function test_first_payment_balance_is_zero_after_full_confirmation(): void
    {
        $tenant = $this->createTenant('FIRST-COMPLETE');
        $subscription = $this->subscription($tenant, '2026-08-20', 3);
        $subscription->update([
            'primer_cobro_monto'=>129,
            'primer_cobro_desde'=>'2026-08-21',
            'primer_cobro_hasta'=>'2026-09-20',
            'primer_cobro_pagado'=>true,
            'estado'=>'activa',
        ]);

        $status = app(SubscriptionAccessService::class)->statusFor($tenant['app']->fresh());

        $this->assertSame(129.0, $status['primer_cobro_monto']);
        $this->assertSame(129.0, $status['primer_cobro_confirmado']);
        $this->assertSame(0.0, $status['primer_cobro_restante']);
        $this->assertTrue($status['primer_cobro_completo']);
        $this->assertTrue($status['primer_cobro_pagado']);
    }
}
