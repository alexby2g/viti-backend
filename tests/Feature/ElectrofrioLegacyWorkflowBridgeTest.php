<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioLegacyWorkflowBridgeTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_legacy_tenant_routes_feed_the_new_workflow_history_until_payment(): void
    {
        $tenant = $this->createTenant('LEGACY-BRIDGE');
        $tenant['plan']->update(['modulos' => [
            'inicio','agenda','ordenes','clientes','equipos','tecnicos','inventario','pagos','garantias','historial','buzon',
        ]]);
        $customer = $this->createFinalCustomer($tenant['company'], 'LEGACY-BRIDGE')['customer'];
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $created = $this->actingAs($tenant['user'])->postJson('/api/v1/mi/apps/electrofrio/ordenes', [
            'cliente_id' => $customer->id,
            'equipo_id' => null,
            'tecnico_id' => null,
            'fecha_cita' => now()->toDateString(),
            'hora_cita' => '09:00',
            'direccion_servicio' => 'Av. Compatible 123',
            'referencia_ubicacion' => null,
            'problema_reportado' => 'No enfría correctamente.',
            'prioridad' => 'normal',
            'diagnostico' => null,
            'propuesta' => null,
            'trabajo_realizado' => null,
            'recomendaciones' => null,
            'costo_mano_obra' => 100,
            'descuento' => 0,
        ], $headers)->assertCreated();

        $orderId = (int) $created->json('data.id');
        $this->assertDatabaseHas('electrofrio_orden_estados', [
            'orden_id' => $orderId,
            'estado' => 'cita_programada',
            'tipo_cambio' => 'creacion',
        ]);

        $this->actingAs($tenant['user'])->putJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}", [
            'cliente_id' => $customer->id,
            'equipo_id' => null,
            'tecnico_id' => null,
            'fecha_cita' => now()->toDateString(),
            'hora_cita' => '09:00',
            'direccion_servicio' => 'Av. Compatible 123',
            'referencia_ubicacion' => null,
            'problema_reportado' => 'No enfría correctamente.',
            'prioridad' => 'normal',
            'diagnostico' => 'Capacitor fuera de rango.',
            'propuesta' => 'Cambio de capacitor y prueba general.',
            'trabajo_realizado' => null,
            'recomendaciones' => null,
            'costo_mano_obra' => 100,
            'descuento' => 0,
        ], $headers)->assertOk();

        $this->assertDatabaseHas('electrofrio_ordenes', ['id' => $orderId, 'estado_actual' => 'esperando_aprobacion']);
        $this->assertDatabaseHas('electrofrio_orden_estados', [
            'orden_id' => $orderId,
            'estado' => 'esperando_aprobacion',
            'tipo_cambio' => 'compatibilidad',
        ]);

        $this->actingAs($tenant['user'])->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/decision", [
            'decision' => 'aceptado',
        ], $headers)->assertOk();

        $this->assertDatabaseHas('electrofrio_orden_estados', ['orden_id' => $orderId, 'estado' => 'aprobado']);
        $this->assertDatabaseHas('electrofrio_ordenes', ['id' => $orderId, 'estado_actual' => 'servicio_en_proceso']);

        $this->actingAs($tenant['user'])->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/finalizar", [
            'trabajo_realizado' => 'Se reemplazó capacitor y se verificó funcionamiento.',
            'recomendaciones' => 'Realizar mantenimiento preventivo cada seis meses.',
            'garantia_dias' => 0,
            'condiciones_garantia' => null,
        ], $headers)->assertOk();

        $this->assertDatabaseHas('electrofrio_ordenes', [
            'id' => $orderId,
            'estado_actual' => 'pendiente_pago',
            'etapa' => 'servicio',
            'finalizada_at' => null,
        ]);
        $this->assertDatabaseHas('electrofrio_orden_estados', ['orden_id' => $orderId, 'estado' => 'servicio_terminado']);
        $this->assertDatabaseHas('electrofrio_orden_estados', ['orden_id' => $orderId, 'estado' => 'pendiente_pago']);

        $this->actingAs($tenant['user'])->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$orderId}/pagos", [
            'monto' => 100,
            'metodo' => 'qr',
            'referencia' => 'LEGACY-QR-100',
        ], $headers)->assertCreated();

        $this->assertDatabaseHas('electrofrio_ordenes', [
            'id' => $orderId,
            'estado_actual' => 'finalizado',
            'etapa' => 'cerrada',
        ]);
        $this->assertDatabaseHas('electrofrio_orden_estados', [
            'orden_id' => $orderId,
            'estado_anterior' => 'pendiente_pago',
            'estado' => 'finalizado',
            'tipo_cambio' => 'compatibilidad',
        ]);
    }
}
