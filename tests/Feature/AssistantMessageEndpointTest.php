<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantMessageEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): Usuario
    {
        return Usuario::create([
            'nombre' => 'Usuario Asistente',
            'usuario' => 'assistant_' . $role,
            'documento' => $role === 'cliente' ? '90000401' : '90000402',
            'telefono' => '70000401',
            'password' => 'Prueba123456',
            'rol' => $role,
            'estado' => 'activo',
        ]);
    }

    public function test_guest_cannot_send_messages(): void
    {
        $this->postJson('/api/v1/assistant/messages', ['message' => 'ayuda'])
            ->assertUnauthorized();
    }

    public function test_client_cannot_access_platform_assistant(): void
    {
        $this->actingAs($this->userWithRole('cliente'))
            ->postJson('/api/v1/assistant/messages', ['message' => 'ayuda'])
            ->assertForbidden();
    }

    public function test_platform_admin_can_use_existing_assistant_logic_via_post(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->postJson('/api/v1/assistant/messages', ['message' => 'ayuda'])
            ->assertOk()
            ->assertJsonPath('intent', 'help')
            ->assertJsonStructure(['message', 'data', 'memory']);
    }

    public function test_empty_and_oversized_messages_are_rejected(): void
    {
        $admin = $this->userWithRole('administrador');

        $this->actingAs($admin)
            ->postJson('/api/v1/assistant/messages', ['message' => '   '])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->postJson('/api/v1/assistant/messages', ['message' => str_repeat('a', 501)])
            ->assertUnprocessable();
    }

    public function test_previous_dashboard_command_path_remains_available(): void
    {
        $this->actingAs($this->userWithRole('administrador'))
            ->getJson('/api/v1/dashboard?agr=ayuda')
            ->assertOk()
            ->assertJsonPath('intent', 'help');
    }
}
