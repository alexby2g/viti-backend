<?php

namespace Tests\Feature;

use App\Models\{Cliente,Cuestionario,Empresa,InvitacionCliente,PlanViti,SolicitudSistema,Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VitiCompleteCommercialFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Admin Flujo Total',
            'usuario' => 'admin_flujo_total',
            'documento' => '99001122',
            'telefono' => '70001122',
            'correo' => 'admin-flujo@viti.test',
            'password' => 'Prueba123456',
            'rol' => 'superadmin',
            'estado' => 'activo',
        ]);
    }

    public function test_approval_creates_access_and_project_waits_for_client_account(): void
    {
        $this->seed(CuestionarioSeeder::class);

        $plan = PlanViti::query()->where('activo', true)->firstOrFail();
        $questionnaire = Cuestionario::query()->where('activo', true)->firstOrFail();
        $client = Cliente::create([
            'nombre' => 'Cliente Flujo Total',
            'telefono' => '71112233',
            'correo' => 'cliente-flujo@viti.test',
            'ciudad' => 'Santa Cruz',
            'estado' => 'informacion_recibida',
        ]);
        $company = Empresa::create([
            'cliente_id' => $client->id,
            'codigo' => 'EMP-FLOW-100',
            'nombre_comercial' => 'Empresa Flujo Total',
            'actividad' => 'Servicios',
            'estado' => 'pendiente_revision',
        ]);
        $request = SolicitudSistema::create([
            'empresa_id' => $company->id,
            'cliente_id' => $client->id,
            'cuestionario_id' => $questionnaire->id,
            'plan_viti_id' => $plan->id,
            'codigo' => 'SOL-FLOW-100',
            'public_token' => Str::random(48),
            'publico_habilitado' => true,
            'titulo' => 'Sistema comercial completo',
            'estado' => 'en_revision',
            'prioridad' => 'normal',
            'declaracion_aceptada' => true,
            'declaracion_nombre' => 'Cliente Flujo Total',
            'declaracion_fecha' => now()->toDateString(),
            'acuerdo_comercial_requerido' => true,
            'acuerdo_comercial_aceptado' => true,
            'acuerdo_comercial_nombre' => 'Cliente Flujo Total',
            'acuerdo_comercial_fecha' => now()->toDateString(),
        ]);
        $admin = $this->admin();

        $approval = $this->actingAs($admin)
            ->postJson('/api/v1/solicitudes/'.$request->id.'/aprobar')
            ->assertOk()
            ->assertJsonPath('data.solicitud.estado', 'aprobada');

        $invitation = InvitacionCliente::query()->where('solicitud_id', $request->id)->firstOrFail();
        $this->assertNotEmpty($approval->json('data.acceso.url'));

        $this->actingAs($admin)->postJson('/api/v1/proyectos', [
            'solicitud_id' => $request->id,
            'empresa_id' => $company->id,
            'cliente_id' => $client->id,
            'nombre' => 'Proyecto bloqueado hasta registro',
            'fase' => 'levantamiento',
            'estado' => 'activo',
            'progreso' => 0,
        ])->assertStatus(422);

        $this->post('/api/v1/publico/registro/'.$invitation->token, [
            'nombre' => 'Cliente Flujo Total',
            'usuario' => 'cliente_flujo_total',
            'telefono' => '71112233',
            'whatsapp' => '71112233',
            'ci' => '8123456',
            'ciudad' => 'Santa Cruz',
            'password' => 'Prueba123456',
            'password_confirmation' => 'Prueba123456',
            'empresa_nombre' => 'Empresa Flujo Total',
            'empresa_actividad' => 'Servicios',
            'empresa_telefono' => '71112233',
            'empresa_whatsapp' => '71112233',
            'empresa_ciudad' => 'Santa Cruz',
            'titulo_sistema' => 'Sistema comercial completo',
            'resumen' => 'Flujo completo de VITI.',
        ])->assertCreated()->assertJsonPath('data.solicitud_estado', 'aprobada');

        $this->assertDatabaseHas('usuarios', [
            'cliente_id' => $client->id,
            'usuario' => 'cliente_flujo_total',
            'rol' => 'cliente',
        ]);
        $this->assertDatabaseHas('invitaciones_clientes', [
            'id' => $invitation->id,
            'estado' => 'usada',
        ]);

        $this->actingAs($admin)->postJson('/api/v1/proyectos', [
            'solicitud_id' => $request->id,
            'empresa_id' => $company->id,
            'cliente_id' => $client->id,
            'nombre' => 'Proyecto VITI real',
            'fase' => 'levantamiento',
            'estado' => 'activo',
            'progreso' => 0,
        ])->assertCreated();

        $this->assertDatabaseHas('solicitudes_sistema', [
            'id' => $request->id,
            'estado' => 'convertida',
        ]);
        $this->assertDatabaseHas('empresas', [
            'id' => $company->id,
            'estado' => 'levantamiento',
        ]);
    }
}
