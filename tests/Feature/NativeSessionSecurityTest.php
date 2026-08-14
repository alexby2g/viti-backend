<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NativeSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_only_own_sessions_and_revoke_other_devices(): void
    {
        $user = $this->createUser('sesiones_uno', 1);
        $android = $this->loginNative($user, 'android-uno', 'android');
        $windows = $this->loginNative($user, 'windows-uno', 'windows');

        $this->withToken($android)
            ->getJson('/api/v1/mobile/sesiones')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['plataforma' => 'android', 'actual' => true])
            ->assertJsonFragment(['plataforma' => 'windows', 'actual' => false]);

        $this->withToken($android)
            ->deleteJson('/api/v1/mobile/sesiones/otras')
            ->assertOk()
            ->assertJsonPath('revocadas', 1);

        $this->withToken($android)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($windows)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseHas('auditoria', [
            'usuario_id' => $user->id,
            'accion' => 'sesiones_nativas_otras_revocadas',
        ]);
    }

    public function test_user_cannot_revoke_another_users_session(): void
    {
        $first = $this->createUser('sesiones_a', 2);
        $second = $this->createUser('sesiones_b', 3);
        $firstToken = $this->loginNative($first, 'device-first', 'android');
        $secondToken = $this->loginNative($second, 'device-second', 'windows');
        $foreignSessionId = $second->fresh()->tokens()->where('name', 'viti-native:device-second')->firstOrFail()->id;

        $this->withToken($firstToken)
            ->deleteJson('/api/v1/mobile/sesiones/'.$foreignSessionId)
            ->assertNotFound();

        $this->withToken($secondToken)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_revoke_all_invalidates_current_and_other_native_tokens(): void
    {
        $user = $this->createUser('sesiones_todas', 4);
        $android = $this->loginNative($user, 'device-all-a', 'android');
        $windows = $this->loginNative($user, 'device-all-b', 'windows');

        $this->withToken($android)
            ->deleteJson('/api/v1/mobile/sesiones/todas')
            ->assertOk()
            ->assertJsonPath('revocadas', 2);

        $this->withToken($android)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($windows)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseHas('auditoria', [
            'usuario_id' => $user->id,
            'accion' => 'sesiones_nativas_todas_revocadas',
        ]);
    }

    private function createUser(string $username, int $suffix): Usuario
    {
        return Usuario::create([
            'nombre' => 'Usuario Sesiones '.$suffix,
            'usuario' => $username,
            'documento' => '9700000'.$suffix,
            'telefono' => '7600000'.$suffix,
            'password' => 'Segura123456',
            'rol' => 'cliente',
            'estado' => 'activo',
        ]);
    }

    private function loginNative(Usuario $user, string $deviceId, string $platform): string
    {
        return (string) $this->postJson('/api/v1/mobile/login', [
            'acceso' => $user->usuario,
            'password' => 'Segura123456',
            'device_id' => $deviceId,
            'device_name' => 'Equipo '.$deviceId,
            'platform' => $platform,
        ])->assertOk()->json('token');
    }
}
