<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class TenantContextConflictTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_header_and_payload_cannot_point_to_different_businesses(): void
    {
        $first = $this->createTenant('CTX-A');
        $second = $this->createTenant('CTX-B');

        $second['company']->usuarios()->attach($first['user']->id,[
            'rol_negocio'=>'administrador',
            'permisos'=>null,
            'activo'=>true,
        ]);

        $this->actingAs($first['user'])
            ->getJson(
                '/api/v1/mi/negocio?empresa_id='.$second['company']->id,
                ['X-VITI-Empresa'=>(string)$first['company']->id]
            )
            ->assertStatus(422)
            ->assertJsonFragment(['message'=>'Contexto de empresa inconsistente.']);
    }

    public function test_matching_header_and_payload_keep_working(): void
    {
        $tenant = $this->createTenant('CTX-C');
        $businessId = $tenant['company']->id;

        $this->actingAs($tenant['user'])
            ->getJson(
                '/api/v1/mi/negocio?empresa_id='.$businessId,
                ['X-VITI-Empresa'=>(string)$businessId]
            )
            ->assertOk()
            ->assertJsonPath('data.empresa.id',$businessId);
    }
}
