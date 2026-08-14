<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class MultiBusinessContextFailSafeTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_single_business_can_still_be_resolved_without_header(): void
    {
        $tenant=$this->createTenant('CTX-ONE');

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/estado')
            ->assertOk()
            ->assertJsonPath('data.empresa.id',$tenant['company']->id);
    }

    public function test_user_with_multiple_businesses_must_send_active_business_context(): void
    {
        $first=$this->createTenant('CTX-A');
        $second=$this->createTenant('CTX-B');
        $second['company']->usuarios()->attach($first['user']->id,[
            'rol_negocio'=>'propietario',
            'permisos'=>null,
            'activo'=>true,
        ]);

        $this->actingAs($first['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/estado')
            ->assertStatus(422)
            ->assertJsonPath('message','Selecciona el negocio activo antes de continuar.');

        $this->actingAs($first['user'])
            ->withHeader('X-VITI-Empresa',(string)$second['company']->id)
            ->getJson('/api/v1/mi/apps/electrofrio/estado')
            ->assertOk()
            ->assertJsonPath('data.empresa.id',$second['company']->id);
    }
}
