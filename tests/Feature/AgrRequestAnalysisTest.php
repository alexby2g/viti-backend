<?php

namespace Tests\Feature;

use App\Models\{Cliente, Cuestionario, Empresa, SolicitudSistema, Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgrRequestAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role, string $suffix): Usuario
    {
        return Usuario::create([
            'nombre' => 'Analista '.$suffix,
            'usuario' => 'analista_'.$suffix,
            'documento' => '98760'.$suffix,
            'telefono' => '78760'.$suffix,
            'password' => 'Prueba123456',
            'rol' => $role,
            'estado' => 'activo',
        ]);
    }

    private function requestForTest(): SolicitudSistema
    {
        $this->seed(CuestionarioSeeder::class);
        $q = Cuestionario::where('activo', true)->firstOrFail();
        $client = Cliente::create([
            'nombre' => 'Contacto Privado',
            'telefono' => '78123456',
            'estado' => 'informacion_recibida',
        ]);
        $company = Empresa::create([
            'cliente_id' => $client->id,
            'codigo' => 'EMP-ANALYSIS-01',
            'nombre_comercial' => 'Empresa Privada',
            'actividad' => 'Servicios',
            'estado' => 'activo',
        ]);
        return SolicitudSistema::create([
            'empresa_id' => $company->id,
            'cliente_id' => $client->id,
            'cuestionario_id' => $q->id,
            'codigo' => 'SOL-00999',
            'public_token' => Str::random(48),
            'titulo' => 'Control de inventario',
            'resumen' => 'Necesito controlar productos y ventas. Contáctame en 78123456 o secreto@example.com',
            'estado' => 'aprobada',
            'prioridad' => 'normal',
        ]);
    }

    public function test_disabled_bridge_does_not_create_analysis_jobs(): void
    {
        config()->set('services.irenet_bridge.enabled', false);
        $this->actingAs($this->actor('administrador', '01'))
            ->postJson('/api/v1/assistant/local/analyze-request', ['codigo' => 'SOL-00999'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'irenet_bridge_disabled');
        $this->assertDatabaseCount('agr_local_inference_jobs', 0);
    }

    public function test_guests_and_clients_cannot_use_request_analysis(): void
    {
        config()->set('services.irenet_bridge.enabled', true);
        $this->postJson('/api/v1/assistant/local/analyze-request', ['codigo' => 'SOL-00999'])
            ->assertUnauthorized();
        $this->actingAs($this->actor('cliente', '02'))
            ->postJson('/api/v1/assistant/local/analyze-request', ['codigo' => 'SOL-00999'])
            ->assertForbidden();
    }

    public function test_analysis_uses_read_only_business_fields_and_redacts_contacts(): void
    {
        config()->set('services.irenet_bridge.enabled', true);
        $request = $this->requestForTest();

        $id = $this->actingAs($this->actor('administrador', '03'))
            ->postJson('/api/v1/assistant/local/analyze-request', ['codigo' => $request->codigo])
            ->assertAccepted()
            ->assertJsonPath('status', 'pending')
            ->json('id');

        $job = DB::table('agr_local_inference_jobs')->where('id', $id)->firstOrFail();
        $this->assertSame($request->codigo, $request->fresh()->codigo);
        $prompt = Crypt::decryptString($job->encrypted_prompt);

        $this->assertStringContainsString('SOL-00999', $prompt);
        $this->assertStringContainsString('Control de inventario', $prompt);
        $this->assertStringContainsString('entidades', $prompt);
        $this->assertStringContainsString('[telefono oculto]', $prompt);
        $this->assertStringContainsString('[correo oculto]', $prompt);
        $this->assertStringNotContainsString('78123456', $prompt);
        $this->assertStringNotContainsString('secreto@example.com', $prompt);
        $this->assertStringNotContainsString('Contacto Privado', $prompt);
        $this->assertStringNotContainsString('Empresa Privada', $prompt);
        $this->assertDatabaseHas('solicitudes_sistema', ['codigo' => 'SOL-00999', 'estado' => 'aprobada']);
        $this->assertDatabaseCount('agr_local_inference_jobs', 1);
    }

    public function test_invalid_and_unknown_codes_do_not_create_jobs(): void
    {
        config()->set('services.irenet_bridge.enabled', true);
        $this->actingAs($this->actor('administrador', '04'))
            ->postJson('/api/v1/assistant/local/analyze-request', ['codigo' => '../admin'])
            ->assertUnprocessable();

        $this->postJson('/api/v1/assistant/local/analyze-request', ['codigo' => 'SOL-99999'])
            ->assertNotFound()
            ->assertJsonPath('code', 'request_not_found');

        $this->assertDatabaseCount('agr_local_inference_jobs', 0);
    }
}
