<?php

namespace Tests\Feature;

use App\Models\InvitacionCliente;
use App\Models\SolicitudAccesoViti;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolicitudAccesoVitiTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Administrador',
            'apellido' => 'VITI',
            'usuario' => 'superadmin-access-test',
            'telefono' => '70000001',
            'password' => 'Password12345',
            'rol' => 'superadmin',
            'estado' => 'activo',
        ]);
    }

    private function pendingRequest(): SolicitudAccesoViti
    {
        return SolicitudAccesoViti::create([
            'nombre' => 'Cliente de Prueba',
            'telefono' => '70000002',
            'whatsapp' => '70000002',
            'negocio' => 'Negocio de Prueba',
            'actividad' => 'Servicios técnicos',
            'mensaje' => 'Solicitud de prueba E2E.',
            'estado' => 'pendiente',
        ]);
    }

    public function test_approving_access_request_creates_one_linked_invitation(): void
    {
        $admin = $this->superAdmin();
        $request = $this->pendingRequest();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/accesos/'.$request->id.'/aprobar', [
                'dias_vigencia' => 7,
                'notas' => 'Aprobada por prueba automatizada.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.solicitud.id', $request->id)
            ->assertJsonStructure(['data' => ['codigo', 'expira_at', 'ruta']]);

        $this->assertMatchesRegularExpression('/^VITI-[A-Z0-9]{6}$/', (string) $response->json('data.codigo'));
        $this->assertDatabaseHas('solicitudes_acceso_viti', [
            'id' => $request->id,
            'estado' => 'aprobada',
        ]);
        $this->assertDatabaseCount('invitaciones_clientes', 1);

        $request->refresh();
        $this->assertNotNull($request->invitacion_id);
        $this->assertDatabaseHas('invitaciones_clientes', [
            'id' => $request->invitacion_id,
            'estado' => 'pendiente',
        ]);
    }

    public function test_approved_access_request_cannot_generate_a_second_invitation(): void
    {
        $admin = $this->superAdmin();
        $request = $this->pendingRequest();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/accesos/'.$request->id.'/aprobar')
            ->assertOk();

        $invitationId = $request->fresh()->invitacion_id;
        $this->assertNotNull($invitationId);
        $this->assertSame(1, InvitacionCliente::query()->count());

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/accesos/'.$request->id.'/aprobar')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Esta solicitud ya fue cerrada.');

        $this->assertSame(1, InvitacionCliente::query()->count());
        $this->assertSame($invitationId, $request->fresh()->invitacion_id);
    }
}
