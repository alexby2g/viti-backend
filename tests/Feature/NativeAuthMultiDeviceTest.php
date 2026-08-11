<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NativeAuthMultiDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_android_and_windows_sessions_can_coexist_and_same_device_rotates_only_itself(): void
    {
        $user = Usuario::create([
            'nombre' => 'Cliente Nativo',
            'usuario' => 'cliente_nativo',
            'documento' => '99110011',
            'telefono' => '71110011',
            'password' => 'Segura123456',
            'rol' => 'cliente',
            'estado' => 'activo',
        ]);

        $android = $this->postJson('/api/v1/mobile/login', [
            'acceso' => 'cliente_nativo',
            'password' => 'Segura123456',
            'device_id' => 'android-device-001',
            'device_name' => 'Galaxy de prueba',
            'platform' => 'android',
        ])->assertOk()->json('token');

        $windows = $this->postJson('/api/v1/mobile/login', [
            'acceso' => 'cliente_nativo',
            'password' => 'Segura123456',
            'device_id' => 'windows-device-001',
            'device_name' => 'PC de prueba',
            'platform' => 'windows',
        ])->assertOk()->json('token');

        $this->assertCount(2, $user->fresh()->tokens);
        $this->withToken($android)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($windows)->getJson('/api/v1/auth/me')->assertOk();

        $androidRotated = $this->postJson('/api/v1/mobile/login', [
            'acceso' => 'cliente_nativo',
            'password' => 'Segura123456',
            'device_id' => 'android-device-001',
            'device_name' => 'Galaxy de prueba',
            'platform' => 'android',
        ])->assertOk()->json('token');

        $tokens = $user->fresh()->tokens;
        $this->assertCount(2, $tokens);
        $this->assertTrue($tokens->contains('name', 'viti-native:android-device-001'));
        $this->assertTrue($tokens->contains('name', 'viti-native:windows-device-001'));
        $this->withToken($androidRotated)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($windows)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_native_login_supports_admin_and_support_but_keeps_superadmin_secret(): void
    {
        config(['app.admin_secret' => 'VITI-SECRET-TEST']);

        foreach ([
            ['usuario' => 'admin_native', 'rol' => 'administrador'],
            ['usuario' => 'support_native', 'rol' => 'soporte'],
        ] as $index => $row) {
            Usuario::create([
                'nombre' => 'Cuenta '.($index + 1),
                'usuario' => $row['usuario'],
                'documento' => '880000'.($index + 1),
                'telefono' => '7200000'.($index + 1),
                'password' => 'Segura123456',
                'rol' => $row['rol'],
                'estado' => 'activo',
            ]);

            $this->postJson('/api/v1/mobile/login', [
                'acceso' => $row['usuario'],
                'password' => 'Segura123456',
                'device_id' => 'device-'.$row['usuario'],
                'device_name' => 'Equipo '.$row['usuario'],
                'platform' => 'windows',
            ])->assertOk()->assertJsonPath('usuario.rol', $row['rol']);
        }

        Usuario::create([
            'nombre' => 'Super Native',
            'usuario' => 'super_native',
            'documento' => '8800099',
            'telefono' => '72000999',
            'password' => 'Segura123456',
            'rol' => 'superadmin',
            'estado' => 'activo',
        ]);

        $payload = [
            'acceso' => 'super_native',
            'password' => 'Segura123456',
            'device_id' => 'device-super-native',
            'device_name' => 'PC Superadmin',
            'platform' => 'windows',
        ];

        $this->postJson('/api/v1/mobile/login', $payload)->assertStatus(422);
        $this->postJson('/api/v1/mobile/login', [...$payload, 'codigo_secreto' => 'VITI-SECRET-TEST'])
            ->assertOk()
            ->assertJsonPath('usuario.rol', 'superadmin');
    }
}
