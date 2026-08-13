<?php

namespace Tests\Feature;

use App\Models\{SystemBackup,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_read_internal_system_health(): void
    {
        $user = $this->user('superadmin', 'super_health', '99000111', '79000111');

        $this->actingAs($user)
            ->getJson('/api/v1/system/health')
            ->assertOk()
            ->assertJsonPath('data.database.ok', true)
            ->assertJsonPath('data.queue.connection', 'sync')
            ->assertJsonPath('data.migrations.ok', true)
            ->assertJsonPath('data.audit.ok', true)
            ->assertJsonPath('data.backup.configured', true)
            ->assertJsonPath('data.backup.status', 'never_run')
            ->assertJsonPath('data.status', 'warning');
    }

    public function test_verified_recent_backup_turns_backup_control_green(): void
    {
        $user = $this->user('superadmin', 'super_backup', '99000112', '79000112');
        SystemBackup::create([
            'status' => 'verified',
            'source' => 'manual',
            'disk' => 'private_uploads',
            'path' => 'system-backups/test.dump',
            'checksum_sha256' => str_repeat('a', 64),
            'size_bytes' => 2048,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/system/health')
            ->assertOk()
            ->assertJsonPath('data.backup.ok', true)
            ->assertJsonPath('data.backup.status', 'verified')
            ->assertJsonPath('data.backup.verified_count', 1)
            ->assertJsonPath('data.backup.size_bytes', 2048)
            ->assertJsonPath('data.status', 'ok');
    }

    public function test_superadmin_can_list_backup_history(): void
    {
        $user = $this->user('superadmin', 'super_backup_list', '99000113', '79000113');
        SystemBackup::create([
            'status' => 'verified',
            'source' => 'manual',
            'disk' => 'private_uploads',
            'path' => 'system-backups/test-list.dump',
            'checksum_sha256' => str_repeat('b', 64),
            'size_bytes' => 4096,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/system/backups')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'verified')
            ->assertJsonPath('data.0.size_bytes', 4096)
            ->assertJsonPath('policy.automatic', false)
            ->assertJsonPath('policy.restore_from_ui', false);
    }

    public function test_regular_admin_cannot_read_internal_system_health_or_backups(): void
    {
        $user = $this->user('administrador', 'admin_health', '99000222', '79000222');

        $this->actingAs($user)->getJson('/api/v1/system/health')->assertForbidden();
        $this->actingAs($user)->getJson('/api/v1/system/backups')->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/system/backups')->assertForbidden();
    }

    private function user(string $role, string $username, string $document, string $phone): Usuario
    {
        return Usuario::create([
            'nombre' => 'Usuario Salud',
            'usuario' => $username,
            'documento' => $document,
            'telefono' => $phone,
            'password' => 'Prueba123456',
            'rol' => $role,
            'estado' => 'activo',
        ]);
    }
}
