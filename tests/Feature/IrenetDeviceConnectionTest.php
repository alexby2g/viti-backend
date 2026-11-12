<?php

namespace Tests\Feature;

use App\Models\{IrenetDevice, Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IrenetDeviceConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role, string $suffix = '1'): Usuario
    {
        return Usuario::create([
            'nombre' => 'Prueba IRENET '.$suffix,
            'usuario' => 'irenet_'.$role.'_'.$suffix,
            'documento' => '99123000'.$suffix,
            'telefono' => '7110000'.$suffix,
            'password' => 'Prueba123456',
            'rol' => $role,
            'estado' => 'activo',
        ]);
    }

    private function newToken(Usuario $owner): array
    {
        return $this->actingAs($owner)
            ->postJson('/api/v1/irenet/dispositivos', ['nombre' => 'Mi computadora Windows'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'nombre', 'token', 'expira_at']])
            ->json('data');
    }

    public function test_pairing_requires_superadmin_session(): void
    {
        $this->postJson('/api/v1/irenet/dispositivos', ['nombre' => 'Equipo de prueba'])
            ->assertUnauthorized();

        $this->actingAs($this->account('cliente'))
            ->postJson('/api/v1/irenet/dispositivos', ['nombre' => 'Equipo de prueba'])
            ->assertForbidden();

        $this->actingAs($this->account('administrador'))
            ->postJson('/api/v1/irenet/dispositivos', ['nombre' => 'Equipo de prueba'])
            ->assertForbidden();
    }

    public function test_pairing_issues_one_time_token_storing_only_hash(): void
    {
        $owner = $this->account('superadmin');
        $data = $this->newToken($owner);

        $this->assertMatchesRegularExpression('/^ivt_[0-9a-f]{64}$/', $data['token']);
        $this->assertDatabaseHas('irenet_devices', [
            'id' => $data['id'],
            'usuario_id' => $owner->id,
            'token_hash' => hash('sha256', $data['token']),
        ]);

        $this->assertNotSame($data['token'], IrenetDevice::firstOrFail()->token_hash);

        $list = $this->actingAs($owner)
            ->getJson('/api/v1/irenet/dispositivos')
            ->assertOk()
            ->assertDontSee('token_hash')
            ->assertDontSee($data['token']);

        $this->assertCount(1, $list->json('data'));
        $this->assertFalse(array_key_exists('token', $list->json('data.0')));
    }

    public function test_only_valid_device_token_can_read_aggregate_metrics(): void
    {
        $owner = $this->account('superadmin');
        $data = $this->newToken($owner);

        $this->getJson('/api/v1/irenet/context')->assertUnauthorized();
        $this->withToken('ivt_'.str_repeat('0', 64))
            ->getJson('/api/v1/irenet/context')->assertUnauthorized();

        $response = $this->withToken($data['token'])
            ->getJson('/api/v1/irenet/context')
            ->assertOk()
            ->assertJsonPath('data.tipo', 'resumen_operativo')
            ->assertJsonPath('data.solo_lectura', true)
            ->assertJsonStructure(['data' => ['consultado_at', 'metricas' => [
                'clientes', 'empresas', 'solicitudes_en_revision', 'proyectos_activos', 'soportes_abiertos',
            ]]]);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('usuario', $response->json('data'));
        $this->assertArrayNotHasKey('token', $response->json('data'));
    }

    public function test_revoking_device_immediately_blocks_its_token(): void
    {
        $owner = $this->account('superadmin');
        $data = $this->newToken($owner);

        $this->withToken($data['token'])
            ->getJson('/api/v1/irenet/context')->assertOk();

        $this->withHeader('Authorization', '');
        $this->actingAs($owner)
            ->deleteJson('/api/v1/irenet/dispositivos/'.$data['id'])
            ->assertOk();

        $this->withToken($data['token'])
            ->getJson('/api/v1/irenet/context')->assertUnauthorized();
    }

    public function test_expired_device_and_disabled_owner_are_denied(): void
    {
        $owner = $this->account('superadmin');
        $data = $this->newToken($owner);

        IrenetDevice::findOrFail($data['id'])->update(['expira_at' => now()->subMinute()]);
        $this->withToken($data['token'])->getJson('/api/v1/irenet/context')
            ->assertUnauthorized();

        IrenetDevice::findOrFail($data['id'])->update(['expira_at' => now()->addDay()]);
        $owner->update(['estado' => 'inactivo']);
        $this->withToken($data['token'])->getJson('/api/v1/irenet/context')
            ->assertUnauthorized();
    }

    public function test_other_superadmin_cannot_revoke_another_users_device(): void
    {
        $owner = $this->account('superadmin', '1');
        $other = $this->account('superadmin', '2');
        $data = $this->newToken($owner);

        $this->actingAs($other)
            ->deleteJson('/api/v1/irenet/dispositivos/'.$data['id'])
            ->assertNotFound();

        $this->assertNull(IrenetDevice::findOrFail($data['id'])->revocado_at);
    }

    public function test_device_token_is_not_a_sanctum_admin_token(): void
    {
        $token = 'ivt_'.str_repeat('a', 64);

        // Device credentials are deliberately not part of Laravel Sanctum.
        $this->withToken($token)->getJson('/api/v1/dashboard')
            ->assertUnauthorized();
    }

    public function test_pairing_limit_is_enforced_before_issuing_sixth_token(): void
    {
        $owner = $this->account('superadmin');
        for ($i = 1; $i <= 5; $i++) {
            IrenetDevice::create([
                'usuario_id' => $owner->id,
                'nombre' => 'Equipo '.$i,
                'token_hash' => hash('sha256', (string) $i),
                'expira_at' => now()->addDays(14),
            ]);
        }

        $this->actingAs($owner)
            ->postJson('/api/v1/irenet/dispositivos', ['nombre' => 'Sexto equipo'])
            ->assertUnprocessable();

        $this->assertSame(5, IrenetDevice::count());
    }
}
