<?php

namespace Tests\Feature;

use App\Models\ElectrofrioCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioWorkflowStatusTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_service_can_advance_through_operational_states_and_keeps_history(): void
    {
        $tenant = $this->createTenant('FLOW-STATES');
        $orderId = $this->order($tenant['company']->id, 'FLOW');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/estado", ['estado' => 'en_visita'], $headers)
            ->assertOk()->assertJsonPath('data.estado_actual', 'en_visita');

        DB::table('electrofrio_ordenes')->where('id', $orderId)->update(['diagnostico' => 'Capacitor deteriorado.']);
        $this->change($tenant['user'], $headers, $orderId, 'diagnostico_realizado');

        DB::table('electrofrio_ordenes')->where('id', $orderId)->update(['propuesta' => 'Reemplazo del capacitor y prueba general.']);
        $this->change($tenant['user'], $headers, $orderId, 'propuesta_enviada');
        $this->change($tenant['user'], $headers, $orderId, 'esperando_aprobacion');
        $this->change($tenant['user'], $headers, $orderId, 'aprobado');
        $this->change($tenant['user'], $headers, $orderId, 'servicio_en_proceso');

        DB::table('electrofrio_ordenes')->where('id', $orderId)->update(['trabajo_realizado' => 'Se reemplazó capacitor y se realizaron pruebas.']);
        $this->change($tenant['user'], $headers, $orderId, 'servicio_terminado');
        $this->change($tenant['user'], $headers, $orderId, 'finalizado');

        $this->assertDatabaseHas('electrofrio_ordenes', [
            'id' => $orderId,
            'empresa_id' => $tenant['company']->id,
            'estado_actual' => 'finalizado',
            'etapa' => 'cerrada',
            'decision_cliente' => 'aceptado',
        ]);
        $this->assertSame(9, DB::table('electrofrio_orden_estados')->where('orden_id', $orderId)->count());
        $this->assertDatabaseHas('electrofrio_orden_estados', [
            'orden_id' => $orderId,
            'estado' => 'cita_programada',
            'tipo_cambio' => 'inicio',
        ]);

        $this->actingAs($tenant['user'])
            ->getJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/estados", $headers)
            ->assertOk()
            ->assertJsonPath('meta.estado_actual', 'finalizado')
            ->assertJsonPath('data.0.estado', 'finalizado');
    }

    public function test_service_cannot_skip_required_states_or_prerequisites(): void
    {
        $tenant = $this->createTenant('FLOW-RULES');
        $orderId = $this->order($tenant['company']->id, 'RULES');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/estado", ['estado' => 'aprobado'], $headers)
            ->assertStatus(422);

        $this->change($tenant['user'], $headers, $orderId, 'en_visita');
        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/estado", ['estado' => 'diagnostico_realizado'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Registra el diagnóstico antes de marcarlo como realizado.');
    }

    public function test_employee_can_advance_flow_but_cannot_force_correction(): void
    {
        $tenant = $this->createTenant('FLOW-EMPLOYEE', 'empleado', ['inicio', 'ordenes']);
        $orderId = $this->order($tenant['company']->id, 'EMPLOYEE');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/estado", ['estado' => 'en_visita'], $headers)
            ->assertOk();

        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/estado", [
                'estado' => 'cita_programada',
                'correccion' => true,
                'observacion' => 'Corrección manual de prueba.',
            ], $headers)
            ->assertForbidden();
    }

    private function change($user, array $headers, int $orderId, string $state): void
    {
        $this->actingAs($user)
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/estado", ['estado' => $state], $headers)
            ->assertOk()
            ->assertJsonPath('data.estado_actual', $state);
    }

    private function order(int $companyId, string $suffix): int
    {
        $customer = ElectrofrioCliente::create([
            'empresa_id' => $companyId,
            'nombre' => 'Cliente '.$suffix,
            'telefono' => '6'.substr(str_pad((string) abs(crc32('flow-'.$suffix)), 8, '0', STR_PAD_LEFT), 0, 8),
            'activo' => true,
        ]);

        return DB::table('electrofrio_ordenes')->insertGetId([
            'empresa_id' => $companyId,
            'codigo' => 'FLOW-'.$suffix.'-'.random_int(1000, 9999),
            'cliente_id' => $customer->id,
            'equipo_id' => null,
            'tecnico_id' => null,
            'fecha_cita' => now()->toDateString(),
            'hora_cita' => '09:00',
            'direccion_servicio' => 'Dirección de prueba',
            'problema_reportado' => 'El equipo no enfría correctamente.',
            'prioridad' => 'normal',
            'etapa' => 'cita',
            'decision_cliente' => 'pendiente',
            'costo_mano_obra' => 0,
            'costo_materiales' => 0,
            'descuento' => 0,
            'total' => 0,
            'garantia_dias' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
