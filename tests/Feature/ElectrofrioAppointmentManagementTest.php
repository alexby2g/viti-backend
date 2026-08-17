<?php

namespace Tests\Feature;

use App\Models\ElectrofrioCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioAppointmentManagementTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_scheduled_appointment_can_be_rescheduled_and_keeps_trace(): void
    {
        $tenant = $this->createTenant('CITA-REPRO');
        $orderId = $this->order($tenant['company']->id, 'REPRO');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];
        $newDate = now()->addDays(2)->toDateString();

        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/reprogramar", [
                'fecha_cita' => $newDate,
                'hora_cita' => '14:30',
                'direccion_servicio' => 'Nueva dirección de visita',
                'referencia_ubicacion' => 'Portón azul',
                'motivo' => 'El cliente solicitó cambiar el horario.',
            ], $headers)
            ->assertOk()
            ->assertJsonPath('data.estado_actual', 'cita_programada')
            ->assertJsonPath('data.fecha_cita', $newDate)
            ->assertJsonPath('data.hora_cita', '14:30');

        $this->assertDatabaseHas('electrofrio_ordenes', [
            'id' => $orderId,
            'empresa_id' => $tenant['company']->id,
            'fecha_cita' => $newDate,
            'hora_cita' => '14:30',
            'direccion_servicio' => 'Nueva dirección de visita',
            'estado_actual' => 'cita_programada',
            'etapa' => 'cita',
        ]);

        $event = DB::table('electrofrio_orden_estados')
            ->where('empresa_id', $tenant['company']->id)
            ->where('orden_id', $orderId)
            ->where('tipo_cambio', 'reprogramacion')
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('cita_programada', $event->estado_anterior);
        $this->assertSame('cita_programada', $event->estado);
        $this->assertStringContainsString('09:00', (string) $event->observacion);
        $this->assertStringContainsString('14:30', (string) $event->observacion);
        $this->assertStringContainsString('El cliente solicitó cambiar el horario.', (string) $event->observacion);
    }

    public function test_visit_can_be_rescheduled_back_to_scheduled_state(): void
    {
        $tenant = $this->createTenant('CITA-VISITA');
        $orderId = $this->order($tenant['company']->id, 'VISITA');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/estado", ['estado' => 'en_visita'], $headers)
            ->assertOk();

        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/reprogramar", [
                'fecha_cita' => now()->addDay()->toDateString(),
                'hora_cita' => '11:15',
                'motivo' => 'No se pudo ingresar al domicilio y se coordinó otra visita.',
            ], $headers)
            ->assertOk()
            ->assertJsonPath('data.estado_actual', 'cita_programada');

        $this->assertDatabaseHas('electrofrio_orden_estados', [
            'empresa_id' => $tenant['company']->id,
            'orden_id' => $orderId,
            'estado_anterior' => 'en_visita',
            'estado' => 'cita_programada',
            'tipo_cambio' => 'reprogramacion',
        ]);
    }

    public function test_appointment_cannot_be_rescheduled_after_diagnosis(): void
    {
        $tenant = $this->createTenant('CITA-BLOQUEO');
        $orderId = $this->order($tenant['company']->id, 'BLOQUEO');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/estado", ['estado' => 'en_visita'], $headers)
            ->assertOk();
        DB::table('electrofrio_ordenes')->where('id', $orderId)->update(['diagnostico' => 'Filtro completamente obstruido.']);
        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/estado", ['estado' => 'diagnostico_realizado'], $headers)
            ->assertOk();

        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/reprogramar", [
                'fecha_cita' => now()->addDay()->toDateString(),
                'hora_cita' => '16:00',
                'motivo' => 'Intento tardío de reprogramación.',
            ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', 'La cita ya avanzó a una etapa que no permite reprogramación. Abre el servicio para gestionar el siguiente paso.');
    }

    public function test_cancellation_requires_reason_and_keeps_history(): void
    {
        $tenant = $this->createTenant('CITA-CANCEL');
        $orderId = $this->order($tenant['company']->id, 'CANCEL');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/estado", ['estado' => 'cancelado'], $headers)
            ->assertStatus(422);

        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/estado", [
                'estado' => 'cancelado',
                'observacion' => 'El cliente canceló la visita antes del desplazamiento.',
            ], $headers)
            ->assertOk()
            ->assertJsonPath('data.estado_actual', 'cancelado');

        $this->assertDatabaseHas('electrofrio_orden_estados', [
            'empresa_id' => $tenant['company']->id,
            'orden_id' => $orderId,
            'estado_anterior' => 'cita_programada',
            'estado' => 'cancelado',
            'tipo_cambio' => 'avance',
            'observacion' => 'El cliente canceló la visita antes del desplazamiento.',
        ]);
    }

    private function order(int $companyId, string $suffix): int
    {
        $customer = ElectrofrioCliente::create([
            'empresa_id' => $companyId,
            'nombre' => 'Cliente '.$suffix,
            'telefono' => '6'.substr(str_pad((string) abs(crc32('appointment-'.$suffix)), 8, '0', STR_PAD_LEFT), 0, 8),
            'activo' => true,
        ]);

        return DB::table('electrofrio_ordenes')->insertGetId([
            'empresa_id' => $companyId,
            'codigo' => 'CITA-'.$suffix.'-'.random_int(1000, 9999),
            'cliente_id' => $customer->id,
            'equipo_id' => null,
            'tecnico_id' => null,
            'fecha_cita' => now()->toDateString(),
            'hora_cita' => '09:00',
            'direccion_servicio' => 'Dirección inicial',
            'referencia_ubicacion' => null,
            'problema_reportado' => 'El equipo no enfría correctamente.',
            'prioridad' => 'normal',
            'etapa' => 'cita',
            'estado_actual' => 'cita_programada',
            'estado_actualizado_at' => now(),
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
