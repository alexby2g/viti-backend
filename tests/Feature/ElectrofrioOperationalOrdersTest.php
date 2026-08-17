<?php

namespace Tests\Feature;

use App\Models\ElectrofrioConfiguracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioOperationalOrdersTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_owner_can_create_update_and_filter_operational_orders(): void
    {
        $tenant = $this->createTenant('OPS-A');
        [$clientId, $equipmentId, $technicianId] = $this->catalog($tenant['company']->id, 'A');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $payload = array_replace($this->orderPayload($clientId, $equipmentId, $technicianId), [
            'tipo_servicio' => 'Mantenimiento preventivo',
        ]);

        $created = $this->actingAs($tenant['user'])
            ->postJson('/api/v1/mi/apps/electrofrio/ordenes-operativas', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('data.etapa', 'cita')
            ->assertJsonPath('data.tipo_servicio', 'Mantenimiento preventivo')
            ->json('data');

        $orderId = (int) $created['id'];
        $this->assertDatabaseHas('electrofrio_ordenes', [
            'id' => $orderId,
            'empresa_id' => $tenant['company']->id,
            'tipo_servicio' => 'Mantenimiento preventivo',
        ]);

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/ordenes-operativas?tipo_servicio='.urlencode('Mantenimiento preventivo').'&tecnico_id='.$technicianId, $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $orderId)
            ->assertJsonPath('meta.resumen.cita', 1);

        $updatedPayload = array_replace($this->orderPayload($clientId, $equipmentId, $technicianId), [
            'tipo_servicio' => 'Reparación',
            'diagnostico' => 'Capacitor fuera de rango y consumo elevado.',
            'propuesta' => 'Cambio de capacitor y prueba de operación.',
            'costo_mano_obra' => 280,
            'descuento' => 20,
        ]);

        $this->actingAs($tenant['user'])
            ->putJson('/api/v1/mi/apps/electrofrio/ordenes-operativas/'.$orderId, $updatedPayload, $headers)
            ->assertOk()
            ->assertJsonPath('data.etapa', 'propuesta')
            ->assertJsonPath('data.tipo_servicio', 'Reparación')
            ->assertJsonPath('data.diagnostico', 'Capacitor fuera de rango y consumo elevado.');

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/ordenes-operativas?etapa=propuesta&tipo_servicio='.urlencode('Reparación').'&buscar=Capacitor', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $orderId)
            ->assertJsonPath('meta.resumen.propuesta', 1);
    }

    public function test_operational_options_follow_business_configuration(): void
    {
        $tenant = $this->createTenant('OPS-CONFIG');
        [$clientId, $equipmentId, $technicianId] = $this->catalog($tenant['company']->id, 'CONFIG');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        ElectrofrioConfiguracion::create([
            'empresa_id' => $tenant['company']->id,
            'tipos_servicio' => ['Inspección técnica', 'Instalación empresarial'],
            'garantia_dias_default' => 45,
            'actualizado_por' => $tenant['user']->id,
        ]);

        $created = $this->actingAs($tenant['user'])
            ->postJson('/api/v1/mi/apps/electrofrio/ordenes-operativas', array_replace(
                $this->orderPayload($clientId, $equipmentId, $technicianId),
                ['tipo_servicio' => 'Inspección técnica']
            ), $headers)
            ->assertCreated()
            ->assertJsonPath('data.tipo_servicio', 'Inspección técnica')
            ->assertJsonPath('data.garantia_dias', 45)
            ->assertJsonPath('data.garantia_dias_default', 45)
            ->json('data');

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/ordenes-operativas', $headers)
            ->assertOk()
            ->assertJsonPath('meta.tipos_servicio.0', 'Inspección técnica')
            ->assertJsonPath('meta.tipos_servicio.1', 'Instalación empresarial')
            ->assertJsonPath('meta.garantia_dias_default', 45)
            ->assertJsonPath('data.0.id', $created['id']);
    }

    public function test_order_detail_is_isolated_between_companies(): void
    {
        $first = $this->createTenant('OPS-B1');
        $second = $this->createTenant('OPS-B2');
        [$clientId, $equipmentId, $technicianId] = $this->catalog($second['company']->id, 'B2');
        $secondHeaders = ['X-VITI-Empresa' => (string) $second['company']->id];

        $created = $this->actingAs($second['user'])
            ->postJson('/api/v1/mi/apps/electrofrio/ordenes-operativas', array_replace(
                $this->orderPayload($clientId, $equipmentId, $technicianId),
                ['tipo_servicio' => 'Instalación']
            ), $secondHeaders)
            ->assertCreated()
            ->json('data');

        $this->actingAs($first['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/ordenes-operativas/'.$created['id'], [
                'X-VITI-Empresa' => (string) $first['company']->id,
            ])
            ->assertNotFound();
    }

    public function test_employee_without_orders_permission_cannot_open_operational_panel(): void
    {
        $tenant = $this->createTenant('OPS-PERM', 'empleado', ['inicio', 'agenda']);

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/ordenes-operativas', [
                'X-VITI-Empresa' => (string) $tenant['company']->id,
            ])
            ->assertForbidden();
    }

    private function catalog(int $companyId, string $suffix): array
    {
        $now = now();
        $clientId = DB::table('electrofrio_clientes')->insertGetId([
            'empresa_id' => $companyId,
            'nombre' => 'Cliente '.$suffix,
            'telefono' => '7'.substr(str_pad((string) abs(crc32('ops-client-'.$suffix)), 8, '0', STR_PAD_LEFT), 0, 8),
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $equipmentId = DB::table('electrofrio_equipos')->insertGetId([
            'empresa_id' => $companyId,
            'cliente_id' => $clientId,
            'tipo' => 'Aire acondicionado Split',
            'marca' => 'Marca '.$suffix,
            'modelo' => 'Modelo '.$suffix,
            'serie' => 'SER-'.$suffix,
            'capacidad' => '12000 BTU',
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $technicianId = DB::table('electrofrio_tecnicos')->insertGetId([
            'empresa_id' => $companyId,
            'nombre' => 'Técnico '.$suffix,
            'especialidad' => 'Climatización',
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$clientId, $equipmentId, $technicianId];
    }

    private function orderPayload(int $clientId, int $equipmentId, int $technicianId): array
    {
        return [
            'cliente_id' => $clientId,
            'equipo_id' => $equipmentId,
            'tecnico_id' => $technicianId,
            'fecha_cita' => '2026-08-20',
            'hora_cita' => '09:30',
            'direccion_servicio' => 'Av. Principal 123',
            'referencia_ubicacion' => 'Frente a la plaza',
            'problema_reportado' => 'El equipo enfría poco y presenta ruido.',
            'prioridad' => 'normal',
            'diagnostico' => null,
            'propuesta' => null,
            'trabajo_realizado' => null,
            'recomendaciones' => null,
            'costo_mano_obra' => 250,
            'descuento' => 0,
        ];
    }
}