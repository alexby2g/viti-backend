<?php

namespace Tests\Feature;

use App\Models\Proyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants, TestCase};

class TenantIsolationRegressionTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_user_cannot_switch_active_company_to_an_unrelated_tenant(): void
    {
        $tenantA = $this->createTenant('ISOLATION-A');
        $tenantB = $this->createTenant('ISOLATION-B');

        $this->actingAs($tenantA['user'])
            ->getJson('/api/v1/mi/negocio/equipo', [
                'X-VITI-Empresa' => (string) $tenantB['company']->id,
            ])
            ->assertStatus(403);
    }

    public function test_user_cannot_manage_a_business_team_in_an_unrelated_tenant(): void
    {
        $tenantA = $this->createTenant('MANAGE-A');
        $tenantB = $this->createTenant('MANAGE-B');

        $member = $this->createTenant('MEMBER-B', 'empleado')['user'];

        $this->actingAs($tenantA['user'])
            ->putJson('/api/v1/mi/negocio/equipo/'.$member->id, [
                'rol_negocio' => 'administrador',
                'activo' => true,
            ], [
                'X-VITI-Empresa' => (string) $tenantB['company']->id,
            ])
            ->assertStatus(403);
    }

    public function test_user_cannot_list_apps_from_an_unrelated_tenant(): void
    {
        $tenantA = $this->createTenant('APPS-A');
        $tenantB = $this->createTenant('APPS-B');

        $this->actingAs($tenantA['user'])
            ->getJson('/api/v1/mi/aplicaciones', [
                'X-VITI-Empresa' => (string) $tenantB['company']->id,
            ])
            ->assertStatus(403);
    }

    public function test_user_cannot_read_project_data_from_an_unrelated_tenant(): void
    {
        $tenantA = $this->createTenant('PROJECT-A');
        $tenantB = $this->createTenant('PROJECT-B');

        Proyecto::create([
            'empresa_id' => $tenantB['company']->id,
            'cliente_id' => null,
            'codigo' => 'PRO-B-CROSS-TENANT',
            'nombre' => 'Proyecto ajeno',
            'fase' => 'levantamiento',
            'estado' => 'activo',
            'progreso' => 10,
        ]);

        $this->actingAs($tenantA['user'])
            ->getJson('/api/v1/mi/proyecto', [
                'X-VITI-Empresa' => (string) $tenantB['company']->id,
            ])
            ->assertStatus(403);
    }
}
