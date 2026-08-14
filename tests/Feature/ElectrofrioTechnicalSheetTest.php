<?php

namespace Tests\Feature;

use App\Models\{ElectrofrioCliente,ElectrofrioEquipo,ElectrofrioFichaTecnica};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioTechnicalSheetTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_owner_can_create_and_update_one_technical_sheet_per_equipment(): void
    {
        $tenant = $this->createTenant('TECH-SHEET');
        $equipment = $this->equipment($tenant['company']->id, 'TS');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->putJson('/api/v1/mi/apps/electrofrio/equipos/'.$equipment->id.'/ficha-tecnica', [
                'gas_refrigerante' => 'r410a',
                'voltaje' => '220v',
                'amperaje_nominal' => 8.75,
                'presion_succion_psi' => 118,
                'presion_descarga_psi' => 365,
                'observaciones_tecnicas' => 'Equipo operando dentro de parámetros.',
            ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.gas_refrigerante', 'R410A')
            ->assertJsonPath('data.voltaje', '220V')
            ->assertJsonPath('data.equipo.id', $equipment->id);

        $this->actingAs($tenant['user'])
            ->putJson('/api/v1/mi/apps/electrofrio/equipos/'.$equipment->id.'/ficha-tecnica', [
                'gas_refrigerante' => 'r32',
                'voltaje' => '220v',
                'amperaje_nominal' => 7.5,
                'presion_succion_psi' => 125,
                'presion_descarga_psi' => 340,
                'observaciones_tecnicas' => 'Medición actualizada.',
            ], $headers)
            ->assertOk()
            ->assertJsonPath('data.gas_refrigerante', 'R32')
            ->assertJsonPath('message', 'Ficha técnica actualizada.');

        $this->assertSame(1, ElectrofrioFichaTecnica::query()->where('equipo_id', $equipment->id)->count());
        $this->assertDatabaseHas('electrofrio_fichas_tecnicas', [
            'empresa_id' => $tenant['company']->id,
            'equipo_id' => $equipment->id,
            'gas_refrigerante' => 'R32',
            'actualizado_por' => $tenant['user']->id,
        ]);
        $this->assertDatabaseHas('auditoria', [
            'empresa_id' => $tenant['company']->id,
            'accion' => 'electrofrio_ficha_tecnica_actualizada',
        ]);
    }

    public function test_business_cannot_read_technical_sheet_from_another_tenant(): void
    {
        $first = $this->createTenant('TECH-A');
        $second = $this->createTenant('TECH-B');
        $foreignEquipment = $this->equipment($second['company']->id, 'FOREIGN');
        ElectrofrioFichaTecnica::create([
            'empresa_id' => $second['company']->id,
            'equipo_id' => $foreignEquipment->id,
            'gas_refrigerante' => 'R410A',
            'voltaje' => '220V',
            'actualizado_por' => $second['user']->id,
        ]);

        $this->actingAs($first['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/equipos/'.$foreignEquipment->id.'/ficha-tecnica', [
                'X-VITI-Empresa' => (string) $first['company']->id,
            ])
            ->assertNotFound();

        $response = $this->actingAs($first['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/fichas-tecnicas', [
                'X-VITI-Empresa' => (string) $first['company']->id,
            ])
            ->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    public function test_employee_without_equipment_permission_cannot_access_technical_sheets(): void
    {
        $tenant = $this->createTenant('TECH-PERM', 'empleado', ['inicio', 'ordenes']);
        $equipment = $this->equipment($tenant['company']->id, 'PERM');

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/equipos/'.$equipment->id.'/ficha-tecnica', [
                'X-VITI-Empresa' => (string) $tenant['company']->id,
            ])
            ->assertForbidden();
    }

    private function equipment(int $companyId, string $suffix): ElectrofrioEquipo
    {
        $customer = ElectrofrioCliente::create([
            'empresa_id' => $companyId,
            'nombre' => 'Cliente técnico '.$suffix,
            'telefono' => '6'.substr(str_pad((string) abs(crc32('tech-'.$suffix)), 8, '0', STR_PAD_LEFT), 0, 8),
            'activo' => true,
        ]);

        return ElectrofrioEquipo::create([
            'empresa_id' => $companyId,
            'cliente_id' => $customer->id,
            'tipo' => 'Aire acondicionado Split',
            'marca' => 'Marca '.$suffix,
            'modelo' => 'Modelo '.$suffix,
            'capacidad' => '12000 BTU',
            'activo' => true,
        ]);
    }
}
