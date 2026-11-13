<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgrIrenetLocalBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'local-bridge-unit-test-secret-should-never-leak';

    private function actor(string $role, string $suffix): Usuario
    {
        return Usuario::create([
            'nombre' => 'Usuario local '.$suffix,
            'usuario' => 'local_'.$suffix,
            'documento' => '92770'.$suffix,
            'telefono' => '72770'.$suffix,
            'password' => 'Prueba123456',
            'rol' => $role,
            'estado' => 'activo',
        ]);
    }

    private function enabled(): void
    {
        config()->set('services.irenet_bridge.enabled', true);
        config()->set('services.irenet_bridge.secret', self::SECRET);
    }

    public function test_bridge_is_disabled_by_default_and_never_processes_messages(): void
    {
        config()->set('services.irenet_bridge.enabled', false);
        $this->actingAs($this->actor('administrador', '001'))
            ->postJson('/api/v1/assistant/local/jobs', ['message' => 'hola'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'irenet_bridge_disabled');

        $this->assertDatabaseCount('agr_local_inference_jobs', 0);
    }

    public function test_guest_and_client_cannot_enqueue_requests(): void
    {
        $this->enabled();
        $this->postJson('/api/v1/assistant/local/jobs', ['message' => 'hola'])->assertUnauthorized();
        $this->actingAs($this->actor('cliente', '002'))
            ->postJson('/api/v1/assistant/local/jobs', ['message' => 'hola'])
            ->assertForbidden();
    }

    public function test_only_same_admin_can_read_the_answer_and_prompts_are_encrypted(): void
    {
        $this->enabled();
        $admin = $this->actor('administrador', '003');
        $other = $this->actor('administrador', '004');
        $id = $this->actingAs($admin)
            ->postJson('/api/v1/assistant/local/jobs', ['message' => 'Explica base de datos'])
            ->assertStatus(202)
            ->json('id');

        $row = DB::table('agr_local_inference_jobs')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertNotSame('Explica base de datos', $row->encrypted_prompt);
        $this->assertSame('Explica base de datos', Crypt::decryptString($row->encrypted_prompt));

        $this->actingAs($other)
            ->getJson('/api/v1/assistant/local/jobs/'.$id)
            ->assertNotFound();

        $this->actingAs($admin)
            ->getJson('/api/v1/assistant/local/jobs/'.$id)
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('message', null);

        $this->getJson('/api/v1/assistant/local/worker/next')
            ->assertForbidden();

        $this->withHeader('X-Irenet-Bridge-Token', 'wrong-secret-with-enough-characters-012345678')
            ->getJson('/api/v1/assistant/local/worker/next')
            ->assertForbidden();

        $job = $this->withHeader('X-Irenet-Bridge-Token', self::SECRET)
            ->getJson('/api/v1/assistant/local/worker/next')
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('message', 'Explica base de datos');

        $this->assertSame($id, $job->json('id'));

        $this->postJson('/api/v1/assistant/local/worker/jobs/'.$id.'/complete', [
            'status' => 'completed',
            'answer' => 'Un sistema de base de datos organiza tablas.',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->actingAs($admin)
            ->getJson('/api/v1/assistant/local/jobs/'.$id)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('message', 'Un sistema de base de datos organiza tablas.');

        $done = DB::table('agr_local_inference_jobs')->where('id', $id)->first();
        $this->assertNotSame('Un sistema de base de datos organiza tablas.', $done->encrypted_answer);

        $this->withHeader('X-Irenet-Bridge-Token', self::SECRET)
            ->postJson('/api/v1/assistant/local/worker/jobs/'.$id.'/complete', [
                'status' => 'completed', 'answer' => 'Respuesta duplicada',
            ])->assertStatus(409);
    }

    public function test_long_local_answers_are_stored_and_returned_without_truncation(): void
    {
        $this->enabled();
        $admin = $this->actor('administrador', '007');
        $id = $this->actingAs($admin)
            ->postJson('/api/v1/assistant/local/jobs', ['message' => 'Informe completo'])
            ->assertAccepted()->json('id');

        $this->withHeader('X-Irenet-Bridge-Token', self::SECRET)
            ->getJson('/api/v1/assistant/local/worker/next')
            ->assertOk()->assertJsonPath('id', $id);

        $longAnswer = str_repeat('Necesidad, módulos, datos, preguntas y riesgos. ', 190);
        $this->withHeader('X-Irenet-Bridge-Token', self::SECRET)
            ->postJson('/api/v1/assistant/local/worker/jobs/'.$id.'/complete', [
                'status' => 'completed',
                'answer' => $longAnswer,
            ])->assertOk();

        $this->actingAs($admin)
            ->getJson('/api/v1/assistant/local/jobs/'.$id)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('message', trim($longAnswer));

        $this->assertGreaterThan(6000, strlen($longAnswer));
        $stored = DB::table('agr_local_inference_jobs')->where('id', $id)->first();
        $this->assertSame(trim($longAnswer), Crypt::decryptString($stored->encrypted_answer));
    }

    public function test_expired_jobs_are_not_given_to_workers(): void
    {
        $this->enabled();
        $id = $this->actingAs($this->actor('administrador', '005'))
            ->postJson('/api/v1/assistant/local/jobs', ['message' => 'prueba'])
            ->assertStatus(202)->json('id');

        DB::table('agr_local_inference_jobs')->where('id', $id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->withHeader('X-Irenet-Bridge-Token', self::SECRET)
            ->getJson('/api/v1/assistant/local/worker/next')
            ->assertNoContent();

        $this->getJson('/api/v1/assistant/local/jobs/'.$id)
            ->assertOk()
            ->assertJsonPath('status', 'expired');
    }

    public function test_failed_or_invalid_jobs_cannot_execute_commands(): void
    {
        $this->enabled();
        $id = $this->actingAs($this->actor('administrador', '006'))
            ->postJson('/api/v1/assistant/local/jobs', ['message' => 'abrir archivos'])
            ->assertStatus(202)->json('id');

        $this->withHeader('X-Irenet-Bridge-Token', self::SECRET)
            ->getJson('/api/v1/assistant/local/worker/next')
            ->assertOk();

        $this->postJson('/api/v1/assistant/local/worker/jobs/'.$id.'/complete', [
            'status' => 'failed',
        ])->assertOk()->assertJsonPath('status', 'failed');

        $this->getJson('/api/v1/assistant/local/jobs/'.$id)
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', null);

        $this->assertDatabaseHas('agr_local_inference_jobs', [
            'id' => $id, 'status' => 'failed',
        ]);
    }
}
