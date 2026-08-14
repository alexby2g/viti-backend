<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioEquipmentHistoryTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_basic_plan_sees_technical_lifecycle_without_premium_financial_or_inventory_data(): void
    {
        $tenant = $this->createTenant('HIST-BASIC');
        $fixture = $this->fixture($tenant['company']->id, 'BASIC');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/historial-equipos?solo_con_historial=1', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fixture['equipmentId'])
            ->assertJsonPath('data.0.ordenes_total', 2)
            ->assertJsonPath('data.0.servicios_cerrados', 1)
            ->assertJsonPath('data.0.evidencias_total', 1)
            ->assertJsonPath('data.0.ficha_tecnica.gas_refrigerante', 'R410A')
            ->assertJsonPath('data.0.pagado_total', null)
            ->assertJsonPath('data.0.garantia_fin', null)
            ->assertJsonPath('meta.resumen.servicios_cerrados', 1)
            ->assertJsonPath('meta.resumen.pagado_total', null);

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/historial-equipos/'.$fixture['equipmentId'], $headers)
            ->assertOk()
            ->assertJsonPath('data.equipo.id', $fixture['equipmentId'])
            ->assertJsonPath('data.equipo.ficha_tecnica.voltaje', '220V')
            ->assertJsonCount(2, 'data.ordenes')
            ->assertJsonPath('data.ordenes.0.materiales', null)
            ->assertJsonPath('data.ordenes.0.pagos', null)
            ->assertJsonPath('data.ordenes.0.garantia_fin', null)
            ->assertJsonPath('data.resumen.pagado_total', null);
    }

    public function test_extended_plan_sees_materials_payments_warranty_and_evidence_in_equipment_history(): void
    {
        $tenant = $this->createTenant('HIST-PREMIUM');
        $modules = $tenant['plan']->modulos ?? [];
        $tenant['plan']->update(['modulos' => array_values(array_unique([
            ...$modules, 'inventario', 'pagos', 'garantias',
        ]))]);
        $fixture = $this->fixture($tenant['company']->id, 'PREMIUM');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $index = $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/historial-equipos?buscar=Samsung', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fixture['equipmentId'])
            ->assertJsonPath('data.0.pagado_total', 300)
            ->assertJsonPath('data.0.garantia_vigente', true)
            ->assertJsonPath('meta.resumen.pagado_total', 300)
            ->assertJsonPath('meta.resumen.garantias_vigentes', 1);

        $detail = $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/historial-equipos/'.$fixture['equipmentId'], $headers)
            ->assertOk()
            ->assertJsonPath('data.resumen.pagado_total', 300)
            ->json('data');

        $closed = collect($detail['ordenes'])->firstWhere('id', $fixture['closedOrderId']);
        $this->assertNotNull($closed);
        $this->assertCount(1, $closed['materiales']);
        $this->assertSame('Capacitor 35uF', $closed['materiales'][0]['material_nombre']);
        $this->assertCount(1, $closed['pagos']);
        $this->assertSame('pagado', $closed['pagos'][0]['estado']);
        $this->assertSame(300.0, (float) $closed['pagado']);
        $this->assertSame(200.0, (float) $closed['saldo']);
        $this->assertCount(1, $closed['evidencias']);
        $this->assertNotNull($closed['garantia_fin']);
    }

    public function test_equipment_history_is_tenant_isolated_and_requires_history_permission(): void
    {
        $first = $this->createTenant('HIST-A');
        $second = $this->createTenant('HIST-B');
        $foreign = $this->fixture($second['company']->id, 'FOREIGN');

        $this->actingAs($first['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/historial-equipos/'.$foreign['equipmentId'], [
                'X-VITI-Empresa' => (string) $first['company']->id,
            ])
            ->assertNotFound();

        $limited = $this->createTenant('HIST-LIMITED', 'empleado', ['inicio', 'ordenes']);
        $this->actingAs($limited['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/historial-equipos', [
                'X-VITI-Empresa' => (string) $limited['company']->id,
            ])
            ->assertForbidden();
    }

    private function fixture(int $companyId, string $suffix): array
    {
        $now = now();
        $clientId = DB::table('electrofrio_clientes')->insertGetId([
            'empresa_id' => $companyId,
            'nombre' => 'Cliente historial '.$suffix,
            'telefono' => '7'.substr(str_pad((string) abs(crc32('hist-client-'.$suffix)), 8, '0', STR_PAD_LEFT), 0, 8),
            'direccion' => 'Av. Historial 123',
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $equipmentId = DB::table('electrofrio_equipos')->insertGetId([
            'empresa_id' => $companyId,
            'cliente_id' => $clientId,
            'tipo' => 'Aire acondicionado Split',
            'marca' => 'Samsung',
            'modelo' => 'WindFree '.$suffix,
            'serie' => 'HIST-'.$suffix,
            'capacidad' => '12000 BTU',
            'ubicacion' => 'Sala principal',
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $technicianId = DB::table('electrofrio_tecnicos')->insertGetId([
            'empresa_id' => $companyId,
            'nombre' => 'Técnico historial '.$suffix,
            'especialidad' => 'Climatización',
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('electrofrio_fichas_tecnicas')->insert([
            'empresa_id' => $companyId,
            'equipo_id' => $equipmentId,
            'gas_refrigerante' => 'R410A',
            'voltaje' => '220V',
            'amperaje_nominal' => 8.5,
            'presion_succion_psi' => 120,
            'presion_descarga_psi' => 350,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $closedOrderId = DB::table('electrofrio_ordenes')->insertGetId([
            'empresa_id' => $companyId,
            'codigo' => 'TMP-HIST-'.bin2hex(random_bytes(4)),
            'cliente_id' => $clientId,
            'equipo_id' => $equipmentId,
            'tecnico_id' => $technicianId,
            'fecha_cita' => '2026-08-01',
            'hora_cita' => '09:00',
            'direccion_servicio' => 'Av. Historial 123',
            'problema_reportado' => 'Equipo no arranca.',
            'tipo_servicio' => 'Reparación',
            'prioridad' => 'alta',
            'etapa' => 'cerrada',
            'decision_cliente' => 'aceptado',
            'diagnostico' => 'Capacitor fuera de rango.',
            'propuesta' => 'Reemplazo de capacitor y pruebas.',
            'trabajo_realizado' => 'Se reemplazó capacitor y se verificó consumo.',
            'recomendaciones' => 'Mantenimiento semestral.',
            'costo_mano_obra' => 350,
            'costo_materiales' => 150,
            'descuento' => 0,
            'total' => 500,
            'garantia_dias' => 90,
            'garantia_inicio' => now()->subDays(10)->toDateString(),
            'garantia_fin' => now()->addDays(80)->toDateString(),
            'condiciones_garantia' => 'Cubre el repuesto instalado y la mano de obra asociada.',
            'finalizada_at' => now()->subDays(10),
            'created_at' => now()->subDays(12),
            'updated_at' => now()->subDays(10),
        ]);
        DB::table('electrofrio_ordenes')->where('id', $closedOrderId)->update([
            'codigo' => 'EF-HIST-'.str_pad((string) $closedOrderId, 5, '0', STR_PAD_LEFT),
        ]);

        $openOrderId = DB::table('electrofrio_ordenes')->insertGetId([
            'empresa_id' => $companyId,
            'codigo' => 'TMP-HIST-'.bin2hex(random_bytes(4)),
            'cliente_id' => $clientId,
            'equipo_id' => $equipmentId,
            'tecnico_id' => $technicianId,
            'fecha_cita' => '2026-08-20',
            'hora_cita' => '15:00',
            'direccion_servicio' => 'Av. Historial 123',
            'problema_reportado' => 'Revisión preventiva.',
            'tipo_servicio' => 'Mantenimiento preventivo',
            'prioridad' => 'normal',
            'etapa' => 'cita',
            'decision_cliente' => 'pendiente',
            'costo_mano_obra' => 0,
            'costo_materiales' => 0,
            'descuento' => 0,
            'total' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('electrofrio_ordenes')->where('id', $openOrderId)->update([
            'codigo' => 'EF-HIST-'.str_pad((string) $openOrderId, 5, '0', STR_PAD_LEFT),
        ]);

        $materialId = DB::table('electrofrio_materiales')->insertGetId([
            'empresa_id' => $companyId,
            'nombre' => 'Capacitor 35uF',
            'unidad' => 'unidad',
            'stock' => 5,
            'stock_minimo' => 1,
            'costo_unitario' => 150,
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('electrofrio_orden_material')->insert([
            'empresa_id' => $companyId,
            'orden_id' => $closedOrderId,
            'material_id' => $materialId,
            'cantidad' => 1,
            'costo_unitario' => 150,
            'subtotal' => 150,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('electrofrio_pagos')->insert([
            'empresa_id' => $companyId,
            'orden_id' => $closedOrderId,
            'monto' => 300,
            'tipo' => 'abono',
            'metodo' => 'qr',
            'referencia' => 'HIST-'.$suffix,
            'estado' => 'pagado',
            'pagado_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('electrofrio_evidencias')->insert([
            'empresa_id' => $companyId,
            'orden_id' => $closedOrderId,
            'categoria' => 'despues',
            'nombre_original' => 'equipo-final.jpg',
            'ruta' => 'electrofrio/test/equipo-final.jpg',
            'mime' => 'image/jpeg',
            'tamano' => 2048,
            'descripcion' => 'Equipo operativo después del servicio.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return compact('clientId', 'equipmentId', 'technicianId', 'closedOrderId', 'openOrderId');
    }
}
