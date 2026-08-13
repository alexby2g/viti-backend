<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_read_internal_system_health(): void
    {
        $user = Usuario::create([
            'nombre' => 'Super Admin Salud',
            'usuario' => 'super_health',
            'documento' => '99000111',
            'telefono' => '79000111',
            'password' => 'Prueba123456',
            'rol' => 'superadmin',
            'estado' => 'activo',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/system/health')
            ->assertOk()
            ->assertJsonPath('data.database.ok', true)
            ->assertJsonPath('data.queue.connection', 'sync')
            ->assertJsonPath('data.migrations.ok', true)
            ->assertJsonPath('data.audit.ok', true)
            ->assertJsonPath('data.backup.configured', false)
            ->assertJsonPath('data.backup.status', 'pending_configuration');
    }

    public function test_regular_admin_cannot_read_internal_system_health(): void
    {
        $user = Usuario::create([
            'nombre' => 'Admin Salud',
            'usuario' => 'admin_health',
            'documento' => '99000222',
            'telefono' => '79000222',
            'password' => 'Prueba123456',
            'rol' => 'administrador',
            'estado' => 'activo',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/system/health')
            ->assertForbidden();
    }
}
