<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioServiceLifecycleIntegrationTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_new_operational_service_starts_with_a_traceable_initial_state(): void
    {
        $tenant = $this->createTenant('SERVICE-INITIAL');
        $customer = $this->createFinalCustomer($tenant['company'], 'SERVICE-INITIAL')['customer'];
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $response = $this->actingAs($tenant['user'])->postJson('/api/v1/mi/apps/electrofrio/ordenes-operativas', [
            'cliente_id' => $customer->id,
            'equipo_id' => null,
            'tecnico_id' => null,
            'tipo_servicio' => 'Diagnóstico',
            'fecha_cita' => now()->toDateString(),
            'hora_cita' => '09:00',
            'direccion_servicio' => 'Av. de prueba 123',
            'referencia_ubicacion' => 'Portón azul',
            'problema_reportado' => 'El equipo no enfría.',
            'prioridad' => 'normal',
            'diagnostico' => null,
            'propuesta' => null,
            'trabajo_realizado' => null,
            'recomendaciones' => null,
            'costo_mano_obra' => 0,
            'descuento' => 0,
        ], $headers)->assertCreated()->assertJsonPath('data.estado_actual', 'cita_programada');

        $orderId = (int) $response->json('data.id');
        $this->assertDatabaseHas('electrofrio_orden_estados', [
            'empresa_id' => $tenant['company']->id,
            'orden_id' => $orderId,
            'estado_anterior' => null,
            'estado' => 'cita_programada',
            'tipo_cambio' => 'creacion',
        ]);
    }

    public function test_full_payment_finalizes_a_finished_service_and_payment_cancellation_reopens_it(): void
    {
        $tenant = $this->createTenant('SERVICE-PAYMENT');
        $tenant['plan']->update(['modulos' => [
            'inicio','agenda','ordenes','clientes','equipos','tecnicos','inventario','pagos','garantias','historial','buzon',
        ]]);
        $customer = $this->createFinalCustomer($tenant['company'], 'SERVICE-PAYMENT')['customer'];
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $orderId = DB::table('electrofrio_ordenes')->insertGetId([
            'empresa_id' => $tenant['company']->id,
            'codigo' => 'PAY-SERVICE-'.random_int(1000,9999),
            'cliente_id' => $customer->id,
            'equipo_id' => null,
            'tecnico_id' => null,
            'fecha_cita' => now()->toDateString(),
            'hora_cita' => '09:00',
            'direccion_servicio' => 'Av. de prueba 456',
            'problema_reportado' => 'No enfría.',
            'prioridad' => 'normal',
            'etapa' => 'servicio',
            'estado_actual' => 'pendiente_pago',
            'estado_actualizado_at' => now(),
            'decision_cliente' => 'aceptado',
            'diagnostico' => 'Capacitor dañado.',
            'propuesta' => 'Reemplazar capacitor.',
            'trabajo_realizado' => 'Capacitor reemplazado y equipo probado.',
            'costo_mano_obra' => 100,
            'costo_materiales' => 0,
            'descuento' => 0,
            'total' => 100,
            'garantia_dias' => 0,
            'servicio_terminado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payment = $this->actingAs($tenant['user'])->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/pagos-operativos", [
            'monto' => 100,
            'tipo' => 'saldo',
            'metodo' => 'qr',
            'referencia' => 'QR-TEST',
            'notas' => 'Pago final',
            'idempotency_key' => 'service-payment-final-0001',
        ], $headers)->assertCreated()->assertJsonPath('meta.servicio_finalizado', true);

        $paymentId = (int) $payment->json('data.id');
        $this->assertDatabaseHas('electrofrio_ordenes', [
            'id' => $orderId,
            'estado_actual' => 'finalizado',
            'etapa' => 'cerrada',
        ]);
        $this->assertDatabaseHas('electrofrio_orden_estados', [
            'orden_id' => $orderId,
            'estado_anterior' => 'pendiente_pago',
            'estado' => 'finalizado',
            'tipo_cambio' => 'automatico',
        ]);

        $this->actingAs($tenant['user'])->postJson("/api/v1/mi/apps/electrofrio/pagos-operativos/{$paymentId}/anular", [
            'motivo' => 'Comprobante invalidado durante la conciliación.',
        ], $headers)->assertOk()->assertJsonPath('meta.servicio_reabierto', true);

        $this->assertDatabaseHas('electrofrio_ordenes', [
            'id' => $orderId,
            'estado_actual' => 'pendiente_pago',
            'etapa' => 'servicio',
            'finalizada_at' => null,
        ]);
        $this->assertDatabaseHas('electrofrio_orden_estados', [
            'orden_id' => $orderId,
            'estado_anterior' => 'finalizado',
            'estado' => 'pendiente_pago',
            'tipo_cambio' => 'automatico',
        ]);
    }
}
