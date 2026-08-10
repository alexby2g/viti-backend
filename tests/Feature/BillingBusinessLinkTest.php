<?php

namespace Tests\Feature;

use App\Models\{Suscripcion,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class BillingBusinessLinkTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_subscription_payment_is_linked_to_company_and_registered_payer(): void
    {
        $tenant = $this->createTenant('PAGO-A');
        $admin = $this->createPlatformAdmin('pago_admin_a');
        $subscription = Suscripcion::create([
            'aplicacion_id'=>$tenant['app']->id,
            'empresa_id'=>$tenant['company']->id,
            'plan'=>'VITI Soporte',
            'monto'=>70,
            'frecuencia'=>'mensual',
            'moneda'=>'BOB',
            'fecha_inicio'=>now()->toDateString(),
            'fecha_vencimiento'=>now()->addMonth()->toDateString(),
            'dias_gracia'=>7,
            'estado'=>'activa',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/suscripciones/'.$subscription->id.'/pagos', [
                'monto'=>70,
                'metodo'=>'transferencia',
                'fecha_pago'=>now()->toDateString(),
                'pagador_usuario_id'=>$tenant['user']->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('suscripcion_pagos', [
            'suscripcion_id'=>$subscription->id,
            'empresa_id'=>$tenant['company']->id,
            'pagador_usuario_id'=>$tenant['user']->id,
            'metodo'=>'transferencia',
        ]);
        $this->assertDatabaseHas('empresas', [
            'id'=>$tenant['company']->id,
            'metodo_pago_preferido'=>'transferencia',
        ]);
    }

    public function test_payment_rejects_payer_from_another_company(): void
    {
        $first = $this->createTenant('PAGO-B1');
        $second = $this->createTenant('PAGO-B2');
        $admin = $this->createPlatformAdmin('pago_admin_b');
        $subscription = Suscripcion::create([
            'aplicacion_id'=>$first['app']->id,
            'empresa_id'=>$first['company']->id,
            'plan'=>'VITI Soporte',
            'monto'=>70,
            'frecuencia'=>'mensual',
            'moneda'=>'BOB',
            'fecha_inicio'=>now()->toDateString(),
            'fecha_vencimiento'=>now()->addMonth()->toDateString(),
            'dias_gracia'=>7,
            'estado'=>'activa',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/suscripciones/'.$subscription->id.'/pagos', [
                'monto'=>70,
                'metodo'=>'qr',
                'fecha_pago'=>now()->toDateString(),
                'pagador_usuario_id'=>$second['user']->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('suscripcion_pagos', [
            'suscripcion_id'=>$subscription->id,
            'pagador_usuario_id'=>$second['user']->id,
        ]);
    }

    private function createPlatformAdmin(string $username): Usuario
    {
        return Usuario::create([
            'nombre'=>'Administrador de pruebas',
            'usuario'=>$username,
            'documento'=>'7'.substr(str_pad((string) abs(crc32($username)), 9, '0', STR_PAD_LEFT), 0, 9),
            'telefono'=>'5'.substr(str_pad((string) abs(crc32('phone-'.$username)), 8, '0', STR_PAD_LEFT), 0, 8),
            'password'=>'Prueba1234',
            'rol'=>'superadmin',
            'estado'=>'activo',
        ]);
    }
}
