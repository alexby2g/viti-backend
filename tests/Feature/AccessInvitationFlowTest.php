<?php

namespace Tests\Feature;

use App\Models\Cuestionario;
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessInvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_public_application_flow_is_retired(): void
    {
        $this->postJson('/api/v1/publico/solicitudes', [
            'nombre' => 'Roberto Pérez',
            'correo' => 'roberto@example.com',
            'telefono' => '7777789',
            'empresa_nombre' => 'Trinicenter',
        ])->assertStatus(410);

        $this->getJson('/api/v1/publico/solicitudes/demo-token')->assertStatus(410);
        $this->putJson('/api/v1/publico/solicitudes/demo-token', [])->assertStatus(410);
        $this->postJson('/api/v1/publico/solicitudes/demo-token/enviar', [])->assertStatus(410);
    }

    public function test_official_viti_application_flow_creates_a_reviewable_request_without_account_access(): void
    {
        $this->seed(CuestionarioSeeder::class);

        $catalog = $this->getJson('/api/v1/publico/solicitud/catalogo')
            ->assertOk()
            ->json('data');

        $plan = collect($catalog['planes'])->firstWhere('codigo', 'basico-1800');
        $this->assertNotNull($plan, 'The active basic plan must be present in the public catalog.');

        $questionnaire = Cuestionario::query()
            ->where('activo', true)
            ->with(['secciones.preguntas'])
            ->latest('id')
            ->firstOrFail();

        $answers = collect($questionnaire->secciones)
            ->flatMap(fn ($section) => $section->preguntas)
            ->filter(fn ($question) => $question->obligatoria)
            ->map(fn ($question) => [
                'pregunta_id' => $question->id,
                'valor' => 'Respuesta de prueba',
            ])->values()->all();

        $payload = [
            'nombre' => 'Roberto Pérez',
            'correo' => 'roberto-'.uniqid().'@example.com',
            'telefono' => '7777789',
            'whatsapp' => '7777789',
            'ciudad' => 'Santa Cruz',
            'empresa_nombre' => 'Trinicenter '.uniqid(),
            'empresa_actividad' => 'Servicios técnicos',
            'empresa_telefono' => '7777789',
            'empresa_whatsapp' => '7777789',
            'empresa_ciudad' => 'Santa Cruz',
            'titulo_sistema' => 'Clientes y pagos',
            'resumen' => 'Solicitud de prueba del flujo oficial.',
            'plan_codigo' => $plan['codigo'],
            'forma_pago_preferida' => '50_50',
            'frecuencia_suscripcion_preferida' => ($plan['precio_mensual'] !== null && $plan['precio_anual'] !== null) ? 'mensual' : null,
            'declaracion_aceptada' => true,
            'declaracion_nombre' => 'Roberto Pérez',
            'declaracion_fecha' => now()->toDateString(),
            'acuerdo_comercial_aceptado' => true,
            'acuerdo_comercial_nombre' => 'Roberto Pérez',
            'acuerdo_comercial_fecha' => now()->toDateString(),
            'respuestas' => $answers,
        ];

        $response = $this->postJson('/api/v1/publico/solicitud/enviar', $payload);
        $this->assertSame(201, $response->status(), $response->getContent());
        $response->assertJsonPath('data.estado', 'en_revision');

        $this->assertDatabaseHas('solicitudes_sistema', [
            'codigo' => $response->json('data.codigo'),
            'estado' => 'en_revision',
        ]);

        $this->assertDatabaseMissing('usuarios', [
            'correo' => $payload['correo'],
        ]);
    }
}
