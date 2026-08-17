<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioDashboardOperativoTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_owner_can_open_operational_dashboard(): void
    {
        $tenant = $this->createTenant('AIR-DASH');

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/dashboard-operativo', [
                'X-VITI-Empresa' => (string) $tenant['company']->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.citas_hoy', 0)
            ->assertJsonPath('data.pendientes_diagnostico', 0)
            ->assertJsonPath('data.esperando_aprobacion', 0)
            ->assertJsonPath('data.servicios_activos', 0)
            ->assertJsonPath('data.ordenes_abiertas', 0)
            ->assertJsonPath('data.agenda_hoy', [])
            ->assertJsonPath('data.ordenes_recientes', []);
    }
}
