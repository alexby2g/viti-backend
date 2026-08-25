<?php

namespace Tests\Feature;

use App\Models\{InvitacionCliente,SolicitudSistema,Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
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
            'api.brevo.com/v3/smtp/email' => Http::response([
                'messageId' => '<viti-test@brevo.local>',
            ], 201),
        ]);

        $requestResponse = $this->postJson('/api/v1/publico/solicitudes', [
            'nombre' => 'Cliente Brevo',
            'correo' => 'cliente@example.com',
            'telefono' => '77771234',
            'ciudad' => 'Santa Cruz',
            'empresa_nombre' => 'Empresa Brevo',
            'empresa_actividad' => 'Servicios',
            'titulo_sistema' => 'Sistema de prueba',
            'resumen' => 'Prueba de envío HTTPS.',
        ])->assertCreated();

        $solicitud = SolicitudSistema::query()
            ->where('codigo', $requestResponse->json('data.solicitud_codigo'))
            ->firstOrFail();

        // The workflow service deliberately prevents draft → approved shortcuts.
        // This test isolates delivery and models the already-approved business state.
        DB::table('solicitudes_sistema')
            ->whereKey($solicitud->id)
            ->update(['estado' => 'aprobada']);
        $solicitud->refresh();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/solicitudes/'.$solicitud->id.'/invitacion', ['dias_vigencia' => 7])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'enviada')
            ->assertJsonPath('data.email_enviado', true);

        $invitation = InvitacionCliente::query()->where('solicitud_id', $solicitud->id)->firstOrFail();
        $this->assertNotNull($invitation->enviada_at);
        $this->assertNull($invitation->error_envio);
        $this->assertSame(1, (int) $invitation->intentos_envio);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->hasHeader('api-key', 'test-brevo-key')
                && $request['sender']['email'] === 'alexby2g@gmail.com'
                && $request['to'][0]['email'] === 'cliente@example.com'
                && $request['replyTo']['email'] === 'alexby2g@gmail.com'
                && str_contains((string) $request['htmlContent'], '/registro-cliente/');
        });
    }
}
