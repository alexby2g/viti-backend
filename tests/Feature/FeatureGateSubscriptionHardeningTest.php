<?php

namespace Tests\Feature;

use App\Models\Suscripcion;
use App\Models\SuscripcionPago;
use App\Models\Usuario;
use App\Services\SubscriptionAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\{CreatesVitiTenants,TestCase};

class FeatureGateSubscriptionHardeningTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_client_business_exposes_canonical_features_and_enforces_user_limit(): void
    {
        $tenant = $this->createTenant('FEATURE-GATE');
        $tenant['plan']->update([
            'modulos'=>['inicio','ordenes','pagos','modulo_inventado'],
            'max_usuarios'=>1,
            'max_aplicaciones'=>1,
        ]);
        $headers = ['X-VITI-Empresa'=>(string)$tenant['company']->id];

        $response = $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/negocio', $headers)
            ->assertOk()
            ->assertJsonPath('data.features.plan.codigo',$tenant['plan']->codigo)
            ->assertJsonPath('data.features.modulos.0','inicio')
            ->assertJsonPath('data.features.modulos.1','ordenes')
            ->assertJsonPath('data.features.modulos.2','pagos')
            ->assertJsonPath('data.features.usuarios.usados',1)
            ->assertJsonPath('data.features.usuarios.maximo',1)
            ->assertJsonPath('data.features.usuarios.restantes',0)
            ->assertJsonPath('data.features.usuarios.alcanzado',true)
            ->assertJsonPath('data.features.aplicaciones.usados',1)
            ->assertJsonPath('data.features.aplicaciones.maximo',1)
            ->assertJsonPath('data.features.aplicaciones.alcanzado',true)
            ->assertJsonPath('data.features.modulos_efectivos.0','inicio')
            ->assertJsonPath('data.features.modulos_efectivos.1','ordenes')
            ->assertJsonPath('data.features.modulos_efectivos.2','pagos');

        $this->assertNotContains('modulo_inventado',(array)$response->json('data.features.modulos'));
        $this->assertNotContains('modulo_inventado',(array)$response->json('data.features.modulos_efectivos'));

        $this->actingAs($tenant['user'])
            ->postJson('/api/v1/mi/negocio/equipo', [
                'nombre'=>'Usuario extra',
                'usuario'=>'usuario_extra_feature',
                'documento'=>'65432109',
                'telefono'=>'71000009',
                'password'=>'Prueba1234',
                'password_confirmation'=>'Prueba1234',
                'rol_negocio'=>'empleado',
                'permisos'=>['inicio'],
            ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message','Este negocio alcanzó el límite de usuarios de su plan VITI.');
    }

    public function test_subscription_reports_due_soon_without_blocking_access(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');
        $tenant = $this->createTenant('DUE-SOON');
        $this->subscription($tenant, '2026-08-18', 3);

        $status = app(SubscriptionAccessService::class)->statusFor($tenant['app']->fresh());

        $this->assertSame('activa',$status['estado']);
        $this->assertSame('por_vencer',$status['etapa_cobro']);
        $this->assertSame(5,$status['dias_para_vencer']);
        $this->assertTrue($status['puede_usar']);
        $this->assertStringContainsString('5 día(s)',$status['mensaje_cobro']);
    }

    public function test_subscription_enters_grace_after_due_date_and_keeps_access(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');
        $tenant = $this->createTenant('GRACE');
        $this->subscription($tenant, '2026-08-12', 3);

        $status = app(SubscriptionAccessService::class)->statusFor($tenant['app']->fresh());

        $this->assertSame('gracia',$status['estado']);
        $this->assertSame('gracia',$status['etapa_cobro']);
        $this->assertSame(1,$status['dias_mora']);
        $this->assertSame('2026-08-15',$status['gracia_hasta']);
        $this->assertTrue($status['puede_usar']);
        $this->assertDatabaseHas('suscripciones',['aplicacion_id'=>$tenant['app']->id,'estado'=>'gracia']);
    }

    public function test_subscription_is_suspended_after_grace_period(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');
        $tenant = $this->createTenant('SUSPENDED');
        $this->subscription($tenant, '2026-08-08', 3);

        $status = app(SubscriptionAccessService::class)->statusFor($tenant['app']->fresh());

        $this->assertSame('suspendida',$status['estado']);
        $this->assertSame('suspendida',$status['etapa_cobro']);
        $this->assertSame(5,$status['dias_mora']);
        $this->assertFalse($status['puede_usar']);
        $this->assertDatabaseHas('suscripciones',['aplicacion_id'=>$tenant['app']->id,'estado'=>'suspendida']);
    }

    public function test_cancelled_subscription_never_reactivates_even_during_trial_window(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');
        $tenant = $this->createTenant('CANCELLED');
        $subscription = $this->subscription($tenant, '2026-08-20', 3);
        $subscription->update([
            'prueba_hasta'=>'2026-08-20',
            'estado'=>'cancelada',
        ]);

        $status = app(SubscriptionAccessService::class)->statusFor($tenant['app']->fresh());

        $this->assertSame('cancelada',$status['estado']);
        $this->assertSame('cancelada',$status['etapa_cobro']);
        $this->assertFalse($status['puede_usar']);
        $this->assertDatabaseHas('suscripciones',[
            'id'=>$subscription->id,
            'estado'=>'cancelada',
        ]);
    }

    public function test_trial_status_is_exposed_consistently_before_due_date(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');
        $tenant = $this->createTenant('TRIAL');
        $tenant['plan']->update(['precio_mensual'=>129]);
        $subscription = $this->subscription($tenant, '2026-09-18', 3);
        $subscription->update([
            'primer_cobro_monto'=>129,
            'prueba_hasta'=>'2026-08-18',
            'primer_cobro_desde'=>'2026-08-19',
            'primer_cobro_hasta'=>'2026-09-18',
            'primer_cobro_pagado'=>false,
        ]);

        $status = app(SubscriptionAccessService::class)->statusFor($tenant['app']->fresh());

        $this->assertTrue($status['en_prueba']);
        $this->assertSame('prueba',$status['etapa_cobro']);
        $this->assertTrue($status['puede_usar']);
        $this->assertFalse($status['requiere_pago']);
        $this->assertSame(129.0,$status['primer_cobro_monto']);
        $this->assertSame('2026-08-19',$status['primer_cobro_desde']);
        $this->assertSame('2026-09-18',$status['primer_cobro_hasta']);
        $this->assertFalse($status['primer_cobro_pagado']);
    }

    public function test_incomplete_first_payment_does_not_extend_subscription_or_reactivate_it(): void
    {
        Carbon::setTestNow('2026-08-20 10:00:00');
        $tenant = $this->createTenant('PARTIAL-PAYMENT');
        $tenant['plan']->update(['precio_mensual'=>129]);
        $subscription = $this->subscription($tenant, '2026-08-20', 3);
        $subscription->update([
            'primer_cobro_monto'=>129,
            'primer_cobro_desde'=>'2026-08-21',
            'primer_cobro_hasta'=>'2026-09-20',
            'primer_cobro_pagado'=>false,
            'estado'=>'suspendida',
        ]);

        $admin = Usuario::create([
            'nombre'=>'Admin Pago',
            'apellido'=>'VITI',
            'usuario'=>'admin_pago_test',
            'telefono'=>'70000081',
            'password'=>'Password12345',
            'rol'=>'superadmin',
            'estado'=>'activo',
        ]);

        $payment = SuscripcionPago::create([
            'suscripcion_id'=>$subscription->id,
            'empresa_id'=>$tenant['company']->id,
            'monto'=>50,
            'metodo'=>'qr',
            'fecha_pago'=>'2026-08-20',
            'estado_revision'=>'pendiente_revision',
            'origen'=>'cliente',
        ]);

        $this->actingAs($admin,'sanctum')
            ->postJson('/api/v1/pagos/suscripcion-pagos/'.$payment->id.'/confirmar')
            ->assertOk();

        $subscription->refresh();
        $this->assertSame('2026-08-20',$subscription->fecha_vencimiento?->format('Y-m-d'));
        $this->assertSame('suspendida',$subscription->estado);
        $this->assertFalse((bool)$subscription->primer_cobro_pagado);
        $this->assertDatabaseHas('suscripcion_pagos',[
            'id'=>$payment->id,
            'estado_revision'=>'confirmado',
        ]);
    }

    public function test_annual_first_payment_extends_one_full_year(): void
    {
        Carbon::setTestNow('2027-09-07 10:00:00');
        $tenant = $this->createTenant('ANNUAL-FIRST');
        $subscription = Suscripcion::create([
            'aplicacion_id'=>$tenant['app']->id,
            'empresa_id'=>$tenant['company']->id,
            'plan'=>$tenant['plan']->nombre,
            'monto'=>1290,
            'frecuencia'=>'anual',
            'moneda'=>'BOB',
            'fecha_inicio'=>'2026-08-24',
            'prueba_hasta'=>'2026-09-06',
            'primer_cobro_monto'=>1290,
            'primer_cobro_desde'=>'2026-09-07',
            'primer_cobro_hasta'=>'2027-09-06',
            'primer_cobro_pagado'=>false,
            'fecha_vencimiento'=>'2026-09-07',
            'dias_gracia'=>7,
            'estado'=>'suspendida',
        ]);

        $admin = Usuario::create([
            'nombre'=>'Admin Anual',
            'apellido'=>'VITI',
            'usuario'=>'admin_annual_payment_test',
            'telefono'=>'70000082',
            'password'=>'Password12345',
            'rol'=>'superadmin',
            'estado'=>'activo',
        ]);

        $payment = SuscripcionPago::create([
            'suscripcion_id'=>$subscription->id,
            'empresa_id'=>$tenant['company']->id,
            'monto'=>1290,
            'metodo'=>'qr',
            'fecha_pago'=>'2027-09-07',
            'estado_revision'=>'pendiente_revision',
            'origen'=>'cliente',
        ]);

        $this->actingAs($admin,'sanctum')
            ->postJson('/api/v1/pagos/suscripcion-pagos/'.$payment->id.'/confirmar')
            ->assertOk();

        $subscription->refresh();
        $this->assertSame('2028-09-06',$subscription->fecha_vencimiento?->format('Y-m-d'));
        $this->assertTrue((bool)$subscription->primer_cobro_pagado);
        $this->assertSame('activa',$subscription->estado);
    }

    public function test_monthly_renewal_extends_one_month_from_current_due_date(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $tenant = $this->createTenant('MONTHLY-RENEWAL');
        $subscription = Suscripcion::create([
            'aplicacion_id'=>$tenant['app']->id,
            'empresa_id'=>$tenant['company']->id,
            'plan'=>$tenant['plan']->nombre,
            'monto'=>129,
            'frecuencia'=>'mensual',
            'moneda'=>'BOB',
            'fecha_inicio'=>'2026-08-01',
            'prueba_hasta'=>null,
            'primer_cobro_monto'=>null,
            'primer_cobro_desde'=>null,
            'primer_cobro_hasta'=>null,
            'primer_cobro_pagado'=>true,
            'fecha_vencimiento'=>'2026-09-30',
            'dias_gracia'=>3,
            'estado'=>'suspendida',
        ]);

        $admin = Usuario::create([
            'nombre'=>'Admin Mensual',
            'apellido'=>'VITI',
            'usuario'=>'admin_monthly_renewal_test',
            'telefono'=>'70000083',
            'password'=>'Password12345',
            'rol'=>'superadmin',
            'estado'=>'activo',
        ]);

        $payment = SuscripcionPago::create([
            'suscripcion_id'=>$subscription->id,
            'empresa_id'=>$tenant['company']->id,
            'monto'=>129,
            'metodo'=>'qr',
            'fecha_pago'=>'2026-10-01',
            'estado_revision'=>'pendiente_revision',
            'origen'=>'cliente',
        ]);

        $this->actingAs($admin,'sanctum')
            ->postJson('/api/v1/pagos/suscripcion-pagos/'.$payment->id.'/confirmar')
            ->assertOk();

        $subscription->refresh();
        $this->assertSame('2026-10-30',$subscription->fecha_vencimiento?->format('Y-m-d'));
        $this->assertSame('activa',$subscription->estado);
    }

    private function subscription(array $tenant, string $due, int $grace): Suscripcion
    {
        return Suscripcion::create([
            'aplicacion_id'=>$tenant['app']->id,
            'empresa_id'=>$tenant['company']->id,
            'plan'=>$tenant['plan']->nombre,
            'monto'=>89,
            'frecuencia'=>'mensual',
            'moneda'=>'BOB',
            'fecha_inicio'=>'2026-07-01',
            'prueba_hasta'=>null,
            'primer_cobro_monto'=>null,
            'primer_cobro_desde'=>null,
            'primer_cobro_hasta'=>null,
            'primer_cobro_pagado'=>false,
            'fecha_vencimiento'=>$due,
            'dias_gracia'=>$grace,
            'estado'=>'activa',
        ]);
    }
}
