<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioWarrantyReentryTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_active_warranty_creates_linked_reentry_with_zero_initial_cost_and_trace(): void
    {
        $tenant = $this->createTenant('GAR-REENTRY');
        $this->enableWarrantyModule($tenant['plan']);
        $original = $this->originalOrder($tenant['company']->id, 'ACTIVE', now()->addDays(20)->toDateString());
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];
        $date = now()->addDay()->toDateString();

        $response = $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$original['orderId']}/reingreso-garantia", [
                'fecha_cita' => $date,
                'hora_cita' => '10:30',
                'motivo' => 'El equipo volvió a presentar la misma falla de enfriamiento.',
                'prioridad' => 'alta',
            ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.orden_origen_garantia_id', $original['orderId'])
            ->assertJsonPath('data.tipo_servicio', 'Reingreso por garantía')
            ->assertJsonPath('data.estado_actual', 'cita_programada')
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.garantia_origen_codigo', $original['code']);

        $reentryId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $reentryId);
        $this->assertDatabaseHas('electrofrio_ordenes', [
            'id' => $reentryId,
            'empresa_id' => $tenant['company']->id,
            'cliente_id' => $original['clientId'],
            'equipo_id' => $original['equipmentId'],
            'orden_origen_garantia_id' => $original['orderId'],
            'tipo_servicio' => 'Reingreso por garantía',
            'estado_actual' => 'cita_programada',
            'etapa' => 'cita',
            'total' => 0,
        ]);
        $this->assertDatabaseHas('electrofrio_orden_estados', [
            'empresa_id' => $tenant['company']->id,
            'orden_id' => $reentryId,
            'estado' => 'cita_programada',
            'tipo_cambio' => 'garantia',
        ]);

        $this->actingAs($tenant['user'])
            ->getJson("/api/v1/mi/apps/electrofrio/ordenes-operativas/{$reentryId}", $headers)
            ->assertOk()
            ->assertJsonPath('data.orden_origen_garantia_id', $original['orderId'])
            ->assertJsonPath('data.motivo_reingreso_garantia', 'El equipo volvió a presentar la misma falla de enfriamiento.');
    }

    public function test_expired_warranty_cannot_create_reentry(): void
    {
        $tenant = $this->createTenant('GAR-EXPIRED');
        $this->enableWarrantyModule($tenant['plan']);
        $original = $this->originalOrder($tenant['company']->id, 'EXPIRED', now()->subDay()->toDateString());

        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$original['orderId']}/reingreso-garantia", [
                'fecha_cita' => now()->addDay()->toDateString(),
                'hora_cita' => '15:00',
                'motivo' => 'Solicitud fuera de vigencia.',
            ], ['X-VITI-Empresa' => (string) $tenant['company']->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La garantía del servicio ya venció.');
    }

    public function test_warranty_reentry_requires_warranty_module(): void
    {
        $tenant = $this->createTenant('GAR-NOMODULE');
        $original = $this->originalOrder($tenant['company']->id, 'NOMODULE', now()->addDays(10)->toDateString());

        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$original['orderId']}/reingreso-garantia", [
                'fecha_cita' => now()->addDay()->toDateString(),
                'motivo' => 'Intento sin módulo de garantías.',
            ], ['X-VITI-Empresa' => (string) $tenant['company']->id])
            ->assertForbidden();
    }

    public function test_warranty_reentry_is_tenant_isolated(): void
    {
        $first = $this->createTenant('GAR-A');
        $second = $this->createTenant('GAR-B');
        $this->enableWarrantyModule($first['plan']);
        $this->enableWarrantyModule($second['plan']);
        $foreign = $this->originalOrder($second['company']->id, 'FOREIGN', now()->addDays(15)->toDateString());

        $this->actingAs($first['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/ordenes/{$foreign['orderId']}/reingreso-garantia", [
                'fecha_cita' => now()->addDay()->toDateString(),
                'motivo' => 'No debe poder enlazar otra empresa.',
            ], ['X-VITI-Empresa' => (string) $first['company']->id])
            ->assertNotFound();
    }

    private function enableWarrantyModule($plan): void
    {
        $modules = $plan->modulos ?? [];
        $plan->update(['modulos' => array_values(array_unique([...$modules, 'garantias']))]);
    }

    private function originalOrder(int $companyId, string $suffix, string $warrantyEnd): array
    {
        $now = now();
        $clientId = DB::table('electrofrio_clientes')->insertGetId([
            'empresa_id' => $companyId,
            'nombre' => 'Cliente garantía '.$suffix,
            'telefono' => '6'.substr(str_pad((string) abs(crc32('gar-client-'.$suffix)), 8, '0', STR_PAD_LEFT), 0, 8),
            'direccion' => 'Av. Garantía 123',
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $equipmentId = DB::table('electrofrio_equipos')->insertGetId([
            'empresa_id' => $companyId,
            'cliente_id' => $clientId,
            'tipo' => 'Aire acondicionado Split',
            'marca' => 'Samsung',
            'modelo' => 'Warranty '.$suffix,
            'serie' => 'GAR-'.$suffix,
            'capacidad' => '12000 BTU',
            'ubicacion' => 'Sala',
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderId = DB::table('electrofrio_ordenes')->insertGetId([
            'empresa_id' => $companyId,
            'codigo' => 'TMP-GAR-'.bin2hex(random_bytes(4)),
            'cliente_id' => $clientId,
            'equipo_id' => $equipmentId,
            'tecnico_id' => null,
            'fecha_cita' => now()->subDays(5)->toDateString(),
            'hora_cita' => '09:00',
            'direccion_servicio' => 'Av. Garantía 123',
            'problema_reportado' => 'Equipo sin enfriamiento.',
            'tipo_servicio' => 'Reparación',
            'prioridad' => 'normal',
            'etapa' => 'cerrada',
            'estado_actual' => 'finalizado',
            'estado_actualizado_at' => now()->subDays(3),
            'decision_cliente' => 'aceptado',
            'diagnostico' => 'Capacitor dañado.',
            'propuesta' => 'Cambio de capacitor.',
            'trabajo_realizado' => 'Se reemplazó capacitor y se probó el equipo.',
            'costo_mano_obra' => 200,
            'costo_materiales' => 100,
            'descuento' => 0,
            'total' => 300,
            'garantia_dias' => 30,
            'garantia_inicio' => now()->subDays(3)->toDateString(),
            'garantia_fin' => $warrantyEnd,
            'condiciones_garantia' => 'Cubre el repuesto instalado y la mano de obra asociada.',
            'servicio_terminado_at' => now()->subDays(3),
            'finalizada_at' => now()->subDays(3),
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(3),
        ]);
        $code = 'EF-GAR-'.str_pad((string) $orderId, 5, '0', STR_PAD_LEFT);
        DB::table('electrofrio_ordenes')->where('id', $orderId)->update(['codigo' => $code]);

        return compact('clientId', 'equipmentId', 'orderId', 'code');
    }
}
