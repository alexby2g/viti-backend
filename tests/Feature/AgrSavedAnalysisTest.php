<?php

namespace Tests\Feature;

use App\Models\{Cliente, Cuestionario, Empresa, SolicitudSistema, Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgrSavedAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role, string $suffix): Usuario
    {
        return Usuario::create([
            'nombre' => 'Analista '.$suffix,
            'usuario' => 'analista_guardado_'.$suffix,
            'documento' => '98870'.$suffix,
            'telefono' => '78870'.$suffix,
            'password' => 'Prueba123456',
            'rol' => $role,
            'estado' => 'activo',
        ]);
    }

    private function requestForTest(): SolicitudSistema
    {
        $this->seed(CuestionarioSeeder::class);
        $questionnaire = Cuestionario::where('activo', true)->firstOrFail();
        $client = Cliente::create([
            'nombre' => 'Cliente Archivo',
            'telefono' => '78900088',
            'estado' => 'informacion_recibida',
        ]);
        $company = Empresa::create([
            'cliente_id' => $client->id,
            'codigo' => 'EMP-SAVE-01',
            'nombre_comercial' => 'Empresa Archivo',
            'actividad' => 'Servicios',
            'estado' => 'activo',
        ]);
        return SolicitudSistema::create([
            'empresa_id' => $company->id,
            'cliente_id' => $client->id,
            'cuestionario_id' => $questionnaire->id,
            'codigo' => 'SOL-00880',
            'public_token' => Str::random(48),
            'titulo' => 'Control de ventas de comidas',
            'resumen' => 'Registrar ventas y consultar ingresos diarios.',
            'estado' => 'aprobada',
            'prioridad' => 'normal',
        ]);
    }

    private function completedAnalysisJob(string $content): string
    {
        $id = $this->postJson('/api/v1/assistant/local/analyze-request', [
            'codigo' => 'SOL-00880',
        ])->assertAccepted()->json('id');

        DB::table('agr_local_inference_jobs')
            ->where('id', $id)
            ->update([
                'status' => 'completed',
                'encrypted_answer' => Crypt::encryptString($content),
                'completed_at' => now(),
            ]);

        return $id;
    }

    public function test_analysis_does_not_save_itself_without_explicit_confirmation(): void
    {
        config()->set('services.irenet_bridge.enabled', true);
        $this->requestForTest();
        $this->actingAs($this->actor('administrador', '01'));

        $this->completedAnalysisJob('Informe pendiente de guardar.');

        $this->getJson('/api/v1/assistant/local/analyses?codigo=SOL-00880')
            ->assertOk()->assertJsonCount(0, 'items');

        $this->assertDatabaseCount('agr_saved_analyses', 0);
        $this->assertDatabaseHas('solicitudes_sistema', ['codigo' => 'SOL-00880', 'estado' => 'aprobada']);
    }

    public function test_an_explicit_save_creates_immutable_versioned_encrypted_drafts(): void
    {
        config()->set('services.irenet_bridge.enabled', true);
        $this->requestForTest();
        $this->actingAs($this->actor('administrador', '02'));

        $firstText = '1. Necesidad principal. 2. Módulos recomendados. 3. Entidades.';
        $firstJob = $this->completedAnalysisJob($firstText);

        $first = $this->postJson('/api/v1/assistant/local/analyses', [
            'job_id' => $firstJob,
        ])->assertCreated()
            ->assertJsonPath('codigo', 'SOL-00880')
            ->assertJsonPath('version', 1)
            ->assertJsonPath('estado', 'borrador')
            ->assertJsonPath('already_saved', false)->json();

        $this->postJson('/api/v1/assistant/local/analyses', [
            'job_id' => $firstJob,
        ])->assertOk()
            ->assertJsonPath('id', $first['id'])
            ->assertJsonPath('version', 1)
            ->assertJsonPath('already_saved', true);
        $this->assertDatabaseCount('agr_saved_analyses', 1);

        $secondText = trim(str_repeat('Reporte completo de análisis local. ', 205));
        $secondJob = $this->completedAnalysisJob($secondText);
        $second = $this->postJson('/api/v1/assistant/local/analyses', [
            'job_id' => $secondJob,
        ])->assertCreated()->assertJsonPath('version', 2)->json();

        $this->getJson('/api/v1/assistant/local/analyses?codigo=SOL-00880')
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.id', $second['id'])
            ->assertJsonPath('items.0.version', 2)
            ->assertJsonPath('items.1.version', 1);

        $saved = DB::table('agr_saved_analyses')->where('id', $second['id'])->first();
        $this->assertNotSame($secondText, $saved->encrypted_content);
        $this->assertSame($secondText, Crypt::decryptString($saved->encrypted_content));
        $this->assertSame('borrador', $saved->estado);

        // The transient worker data is safely removable without losing history.
        DB::table('agr_local_inference_jobs')->delete();

        $this->getJson('/api/v1/assistant/local/analyses/'.$first['id'])
            ->assertOk()->assertJsonPath('content', $firstText);

        $this->getJson('/api/v1/assistant/local/analyses/'.$second['id'])
            ->assertOk()->assertJsonPath('content', $secondText);

        $this->assertDatabaseHas('solicitudes_sistema', ['codigo' => 'SOL-00880', 'estado' => 'aprobada']);
    }

    public function test_generic_chat_jobs_cannot_be_saved_as_request_analyses(): void
    {
        config()->set('services.irenet_bridge.enabled', true);
        $this->requestForTest();
        $this->actingAs($this->actor('administrador', '03'));

        $genericJob = $this->postJson('/api/v1/assistant/local/jobs', [
            'message' => 'Hola, ¿qué puedes hacer?',
        ])->assertAccepted()->json('id');

        DB::table('agr_local_inference_jobs')->where('id', $genericJob)->update([
            'status' => 'completed',
            'encrypted_answer' => Crypt::encryptString('Respuesta general'),
        ]);

        $this->postJson('/api/v1/assistant/local/analyses', [
            'job_id' => $genericJob,
        ])->assertNotFound();

        $this->assertDatabaseCount('agr_saved_analyses', 0);
    }

    public function test_unfinished_jobs_are_not_archived_and_other_admins_cannot_save_them(): void
    {
        config()->set('services.irenet_bridge.enabled', true);
        $this->requestForTest();
        $owner = $this->actor('administrador', '04');
        $other = $this->actor('administrador', '05');

        $jobId = $this->actingAs($owner)
            ->postJson('/api/v1/assistant/local/analyze-request', ['codigo' => 'SOL-00880'])
            ->assertAccepted()->json('id');

        $this->postJson('/api/v1/assistant/local/analyses', [
            'job_id' => $jobId,
        ])->assertStatus(409);

        $this->actingAs($other)
            ->postJson('/api/v1/assistant/local/analyses', [
                'job_id' => $jobId,
            ])->assertNotFound();

        $this->assertDatabaseCount('agr_saved_analyses', 0);
    }

    public function test_only_platform_admin_can_save_and_read_history(): void
    {
        $this->postJson('/api/v1/assistant/local/analyses', [
            'job_id' => (string) Str::uuid(),
        ])->assertUnauthorized();

        $this->getJson('/api/v1/assistant/local/analyses?codigo=SOL-00880')
            ->assertUnauthorized();

        $this->actingAs($this->actor('cliente', '06'))
            ->postJson('/api/v1/assistant/local/analyses', [
                'job_id' => (string) Str::uuid(),
            ])->assertForbidden();

        $this->getJson('/api/v1/assistant/local/analyses?codigo=SOL-00880')
            ->assertForbidden();
    }

    public function test_invalid_codes_and_unknown_analyses_do_not_expose_records(): void
    {
        $this->actingAs($this->actor('administrador', '07'));

        $this->postJson('/api/v1/assistant/local/analyses', [
            'job_id' => 'not-uuid',
        ])->assertUnprocessable();

        $this->getJson('/api/v1/assistant/local/analyses?codigo=../admin')
            ->assertUnprocessable();

        $this->getJson('/api/v1/assistant/local/analyses/9999999')
            ->assertNotFound();

        $this->assertDatabaseCount('agr_saved_analyses', 0);
    }
}
