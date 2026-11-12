<?php

namespace Tests\Feature;

use App\Models\{Cliente, Cuestionario, Empresa, SolicitudSistema, Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgrAttentionDialogueTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Administrador Atencion',
            'usuario' => 'admin_atencion',
            'documento' => '90000581',
            'telefono' => '70000581',
            'password' => 'Prueba123456',
            'rol' => 'administrador',
            'estado' => 'activo',
        ]);
    }

    private function createRequest(string $status, int $number): SolicitudSistema
    {
        $questionnaire = Cuestionario::query()->where('activo', true)->firstOrFail();
        $client = Cliente::create([
            'nombre' => 'Cliente de prueba '.$number,
            'telefono' => '69000'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'estado' => 'informacion_recibida',
        ]);
        $company = Empresa::create([
            'cliente_id' => $client->id,
            'codigo' => 'EMP-ATT-'.$number,
            'nombre_comercial' => 'Empresa Attention '.$number,
            'actividad' => 'Servicios',
            'estado' => 'activo',
        ]);

        return SolicitudSistema::create([
            'empresa_id' => $company->id,
            'cliente_id' => $client->id,
            'cuestionario_id' => $questionnaire->id,
            'codigo' => 'SOL-ATT-'.$number,
            'public_token' => Str::random(48),
            'titulo' => 'Solicitud de prueba '.$number,
            'estado' => $status,
            'prioridad' => 'normal',
        ]);
    }

    public function test_attention_excludes_finished_and_draft_requests_and_lists_actionable_ones(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $this->createRequest('en_revision', 1);
        $this->createRequest('aprobada', 2);
        $this->createRequest('convertida', 3);
        $this->createRequest('cerrada', 4);
        $this->createRequest('borrador', 5);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/assistant/messages', ['message' => 'resumen'])
            ->assertOk()
            ->assertJsonPath('intent', 'summary')
            ->assertJsonPath('data.requests', 2);

        $response = $this->postJson('/api/v1/assistant/messages', ['message' => '¿qué necesita atención?'])
            ->assertOk()
            ->assertJsonPath('intent', 'attention')
            ->assertJsonPath('data.requests_review', 1)
            ->assertJsonPath('data.requests_approved', 1)
            ->assertJsonPath('meta.read_only', true);

        $this->assertCount(2, $response->json('data.requests'));
        $this->assertStringContainsString('SOL-ATT-1', $response->json('message'));
        $this->assertStringContainsString('SOL-ATT-2', $response->json('message'));
        $this->assertStringNotContainsString('SOL-ATT-3', $response->json('message'));
        $this->assertStringNotContainsString('SOL-ATT-4', $response->json('message'));
        $this->assertDatabaseHas('solicitudes_sistema', ['codigo' => 'SOL-ATT-3', 'estado' => 'convertida']);
    }

    public function test_followup_which_ones_after_summary_uses_current_database_states(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $this->createRequest('en_revision', 6);
        $this->actingAs($this->admin());

        $this->postJson('/api/v1/assistant/messages', ['message' => 'resumen'])
            ->assertOk()->assertJsonPath('intent', 'summary');

        $this->postJson('/api/v1/assistant/messages', ['message' => '¿cuáles son?'])
            ->assertOk()
            ->assertJsonPath('intent', 'attention')
            ->assertJsonPath('data.requests_review', 1);
    }

    public function test_no_pending_attention_has_honest_empty_state(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/assistant/messages', ['message' => 'qué requiere atención'])
            ->assertOk()
            ->assertJsonPath('intent', 'attention')
            ->assertJsonPath('data.requests_review', 0)
            ->assertJsonPath('data.requests_approved', 0)
            ->assertJsonPath('data.payments_attention', 0)
            ->assertJsonPath('data.support_open', 0);
    }

    public function test_attention_is_read_only_and_does_not_change_a_request_state(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $this->createRequest('en_revision', 7);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/assistant/messages', ['message' => 'qué debo atender'])
            ->assertOk()
            ->assertJsonPath('intent', 'attention');

        $this->assertDatabaseHas('solicitudes_sistema', [
            'codigo' => 'SOL-ATT-7',
            'estado' => 'en_revision',
        ]);
    }
}
