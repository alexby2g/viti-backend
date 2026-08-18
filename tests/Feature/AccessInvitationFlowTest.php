<?php

namespace Tests\Feature;

use App\Mail\VitiAccessInvitation;
use App\Models\{Cliente,Empresa,InvitacionCliente,SolicitudSistema,Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccessInvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Admin Invitaciones',
            'usuario' => 'admin_invitaciones',
            'documento' => '99000123',
            'telefono' => '79900123',
            'correo' => 'admin@viti.test',
            'password' => 'Prueba123456',
            'rol' => 'superadmin',
            'estado' => 'activo',
        ]);
    }

    public function test_access_request_invitation_and_registration_reuse_the_same_business_records(): void
    {
        $this->seed(CuestionarioSeeder::class);
        Mail::fake();
        config(['mail.default' => 'smtp']);

        $requestResponse = $this->postJson('/api/v1/publico/solicitudes', [
            'nombre' => 'Roberto Pérez',
            'correo' => 'roberto@example.com',
            'telefono' => '7777789',
            'whatsapp' => '7777789',
            'ciudad' => 'Santa Cruz',
            'empresa_nombre' => 'Trinicenter',
            'empresa_actividad' => 'Servicios técnicos a dispositivos móviles',
            'titulo_sistema' => 'Clientes y pagos',
            'resumen' => 'Necesito organizar clientes, trabajos y pagos.',
        ])->assertCreated();

        $solicitud = SolicitudSistema::query()->where('codigo', $requestResponse->json('data.solicitud_codigo'))->firstOrFail();
        $clienteId = $solicitud->cliente_id;
        $empresaId = $solicitud->empresa_id;

        $inviteResponse = $this->actingAs($this->admin())
            ->postJson('/api/v1/solicitudes/'.$solicitud->id.'/invitacion', ['dias_vigencia' => 7])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'enviada')
            ->assertJsonPath('data.correo', 'roberto@example.com');

        $invitation = InvitacionCliente::query()->where('solicitud_id', $solicitud->id)->firstOrFail();
        $this->assertSame($clienteId, $invitation->cliente_id);
        $this->assertSame('roberto@example.com', $invitation->correo_destino);
        Mail::assertSent(VitiAccessInvitation::class, fn ($mail) => $mail->hasTo('roberto@example.com'));

        $this->getJson('/api/v1/publico/registro/'.$invitation->token)
            ->assertOk()
            ->assertJsonPath('data.vinculada_solicitud', true)
            ->assertJsonPath('data.prefill.empresa_nombre', 'Trinicenter')
            ->assertJsonPath('data.correo', 'roberto@example.com');

        $this->post('/api/v1/publico/registro/'.$invitation->token, [
            'nombre' => 'Roberto Pérez',
            'usuario' => 'roberto_viti',
            'telefono' => '7777789',
            'whatsapp' => '7777789',
            'ci' => '12345678',
            'ci_expedido' => 'SC',
            'ciudad' => 'Santa Cruz',
            'direccion' => 'Zona Centro',
            'password' => 'Registro12345',
            'password_confirmation' => 'Registro12345',
            'empresa_nombre' => 'Trinicenter',
            'empresa_actividad' => 'Servicios técnicos a dispositivos móviles',
            'empresa_telefono' => '7777789',
            'empresa_whatsapp' => '7777789',
            'empresa_ciudad' => 'Santa Cruz',
            'titulo_sistema' => 'Clientes y pagos',
            'resumen' => 'Necesito organizar clientes, trabajos y pagos.',
        ])->assertCreated();

        $this->assertSame(1, Cliente::query()->where('correo', 'roberto@example.com')->count());
        $this->assertSame(1, Empresa::query()->whereKey($empresaId)->count());
        $this->assertSame(1, SolicitudSistema::query()->whereKey($solicitud->id)->count());
        $this->assertDatabaseHas('usuarios', [
            'cliente_id' => $clienteId,
            'usuario' => 'roberto_viti',
            'correo' => 'roberto@example.com',
        ]);
        $this->assertDatabaseHas('invitaciones_clientes', [
            'id' => $invitation->id,
            'estado' => 'usada',
            'cliente_id' => $clienteId,
            'solicitud_id' => $solicitud->id,
        ]);
    }
}
