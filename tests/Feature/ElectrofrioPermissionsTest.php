<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioPermissionsTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_employee_permissions_filter_the_menu_and_protect_the_api(): void
    {
        $tenant = $this->createTenant('PERMISOS', 'empleado', ['inicio','agenda','ordenes']);
        $headers = ['X-VITI-Empresa'=>(string)$tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/estado', $headers)
            ->assertOk()
            ->assertJsonPath('data.plan.modulos', ['inicio','agenda','ordenes']);

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/ordenes', $headers)
            ->assertOk();

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/clientes', $headers)
            ->assertForbidden();

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/negocio/equipo', $headers)
            ->assertForbidden();
    }
}
