<?php

namespace Tests\Feature;

use App\Models\{Cuestionario,SolicitudSistema,Usuario};
use App\Services\BrevoTransactionalEmailService;
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrevoInvitationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Admin Brevo',
            'usuario' => 'admin_brevo',
            'documento' => '99123456',
            'telefono' => '79912345',
            'correo' => 'admin@viti.test',
            'password' => 'Prueba123456',
            'rol' => 'superadmin',
            'estado' => 'activo',
        ]);
    }

    public function test_invitation_can_be_delivered_through_brevo_https_api(): void
    {
        $this->seed(CuestionarioSeeder::class);
        config([
            'services.transactional_mail.provider' => 'brevo',
            'services.brevo.api_url' => 'https://api.brevo.com/v3',
            'services.brevo.api_key' => 'test-brevo-key',
            'services.brevo.from_email' => 'alexby2g@gmail.com',
            'services.brevo.from_name' => 'AGR Studio · VITI',
            'services.brevo.reply_to_email' => 'alexby2g@gmail.com',
            'services.brevo.reply_to_name' => 'AGR Studio · VITI',
        ]);

        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<viti-test@brevo.local>'], 201),
        ]);

        $catalog = $this->getJson('/api/v1/publico/solicitud/catalogo')->assertOk()->json('data');
        $plan = collect($catalog['planes'])->firstWhere('codigo', 'basico-1800');
        $this->assertNotNull($plan);

        $questionnaire = Cuestionario::query()->where('activo', true)->with(['secciones.preguntas'])->latest('id')->firstOrFail();
        $answers = collect($questionnaire->secciones)
            ->flatMap(fn ($section) => $section->preguntas)
            ->filter(fn ($question) => $question->obligatoria)
            ->map(fn ($question) => ['pregunta_id' => $question->id, 'valor' => 'Respuesta de prueba'])
            ->values()->all();

        $requestResponse = $this->postJson('/api/v1/publico/solicitud/enviar', [
            'nombre' => 'Cliente Brevo',
            'correo' => 'cliente-'.uniqid().'@example.com',
            'telefono' => '77771234',
            'whatsapp' => '77771234',
            'ciudad' => 'Santa Cruz',
            'empresa_nombre' => 'Empresa Brevo '.uniqid(),
            'empresa_actividad' => 'Servicios',
            'titulo_sistema' => 'Sistema de prueba',
            'resumen' => 'Prueba de envío HTTPS.',
            'plan_codigo' => $plan['codigo'],
            'forma_pago_preferida' => '50_50',
            'frecuencia_suscripcion_preferida' => 'mensual',
            'declaracion_aceptada' => true,
            'declaracion_nombre' => 'Cliente Brevo',
            'declaracion_fecha' => now()->toDateString(),
            'acuerdo_comercial_aceptado' => true,
            'acuerdo_comercial_nombre' => 'Cliente Brevo',
            'acuerdo_comercial_fecha' => now()->toDateString(),
            'respuestas' => $answers,
        ])->assertCreated();

        $solicitud = SolicitudSistema::query()->where('codigo', $requestResponse->json('data.codigo'))->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/solicitudes/'.$solicitud->id.'/invitacion', ['dias_vigencia' => 7])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'enviada')
            ->assertJsonPath('data.email_enviado', true);

        $this->assertDatabaseHas('invitaciones_clientes', ['solicitud_id' => $solicitud->id]);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->hasHeader('api-key', 'test-brevo-key')
                && $request['sender']['email'] === 'alexby2g@gmail.com';
        });
    }
}
