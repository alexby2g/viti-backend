<?php

namespace Tests\Feature;

use App\Models\{Auditoria,Cliente,Cuestionario,Empresa,SolicitudSistema,Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequestLifecycleEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Admin Flujo',
            'usuario' => 'admin_fase2',
            'documento' => '90500123',
            'telefono' => '70050123',
            'password' => 'Prueba123456',
            'rol' => 'superadmin',
            'estado' => 'activo',
        ]);
    }

    private function solicitud(string $estado = 'en_revision'): SolicitudSistema
    {
        $this->seed(CuestionarioSeeder::class);
        $cliente = Cliente::create([
            'nombre' => 'Cliente Fase Dos',
            'telefono' => '73995001',
            'correo' => 'fase2@example.test',
            'estado' => 'informacion_recibida',
        ]);
        $empresa = Empresa::create([
            'cliente_id' => $cliente->id,
            'codigo' => 'EMP-F2',
            'nombre_comercial' => 'Empresa Fase Dos',
            'actividad' => 'Servicios',
            'estado' => 'activo',
        ]);
        $cuestionario = Cuestionario::query()->where('activo', true)->firstOrFail();

        return SolicitudSistema::create([
            'empresa_id' => $empresa->id,
            'cliente_id' => $cliente->id,
            'cuestionario_id' => $cuestionario->id,
            'codigo' => 'SOL-F2-1',
            'public_token' => Str::random(48),
            'publico_habilitado' => true,
            'titulo' => 'Solicitud de prueba fase dos',
            'estado' => $estado,
            'prioridad' => 'normal',
        ]);
    }

    public function test_admin_can_reject_request_with_reason_and_reason_is_audited(): void
    {
        $solicitud = $this->solicitud();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/solicitudes/'.$solicitud->id.'/rechazar', [
                'motivo' => 'Falta aclarar el alcance y los datos comerciales.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'rechazada');

        $this->assertDatabaseHas('solicitudes_sistema', [
            'id' => $solicitud->id,
            'estado' => 'rechazada',
        ]);
        $this->assertDatabaseHas('auditoria', [
            'accion' => 'solicitud_rechazada',
            'entidad_tipo' => SolicitudSistema::class,
            'entidad_id' => $solicitud->id,
        ]);
    }

    public function test_request_timeline_only_returns_events_from_selected_request(): void
    {
        $solicitud = $this->solicitud();
        $admin = $this->admin();

        Auditoria::create([
            'usuario_id' => $admin->id,
            'accion' => 'evento_prueba',
            'entidad_tipo' => SolicitudSistema::class,
            'entidad_id' => $solicitud->id,
            'descripcion' => 'Evento de la solicitud.',
        ]);
        Auditoria::create([
            'usuario_id' => $admin->id,
            'accion' => 'evento_ajeno',
            'entidad_tipo' => SolicitudSistema::class,
            'entidad_id' => 999999,
            'descripcion' => 'No debe aparecer.',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/solicitudes/'.$solicitud->id.'/historial')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.accion', 'evento_prueba');
    }
}
