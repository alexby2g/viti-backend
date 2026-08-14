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

    public function test_user_cannot_force_context_to_a_foreign_business_by_header(): void
    {
        $owned=$this->createTenant('CTX-OWNED');
        $foreign=$this->createTenant('CTX-FOREIGN');

        $this->actingAs($owned['user'])
            ->withHeader('X-VITI-Empresa',(string)$foreign['company']->id)
            ->getJson('/api/v1/mi/apps/electrofrio/estado')
            ->assertForbidden()
            ->assertJsonPath('message','No tienes acceso a este negocio.');
    }

    public function test_conflicting_header_and_payload_business_context_is_rejected(): void
    {
        $first=$this->createTenant('CTX-CONFLICT-A');
        $second=$this->createTenant('CTX-CONFLICT-B');
        $second['company']->usuarios()->attach($first['user']->id,[
            'rol_negocio'=>'propietario',
            'permisos'=>null,
            'activo'=>true,
        ]);

        $this->actingAs($first['user'])
            ->withHeader('X-VITI-Empresa',(string)$first['company']->id)
            ->getJson('/api/v1/mi/apps/electrofrio/estado?empresa_id='.$second['company']->id)
            ->assertStatus(422)
            ->assertJsonPath('message','Contexto de empresa inconsistente.');
    }

    public function test_inactive_membership_cannot_be_used_as_business_context(): void
    {
        $active=$this->createTenant('CTX-ACTIVE');
        $inactive=$this->createTenant('CTX-INACTIVE');
        $inactive['company']->usuarios()->attach($active['user']->id,[
            'rol_negocio'=>'propietario',
            'permisos'=>null,
            'activo'=>false,
        ]);

        $this->actingAs($active['user'])
            ->withHeader('X-VITI-Empresa',(string)$inactive['company']->id)
            ->getJson('/api/v1/mi/apps/electrofrio/estado')
            ->assertForbidden()
            ->assertJsonPath('message','No tienes acceso a este negocio.');
    }

    public function test_invalid_non_numeric_header_is_rejected_even_with_a_single_business(): void
    {
        $tenant=$this->createTenant('CTX-BAD-HEADER');

        $this->actingAs($tenant['user'])
            ->withHeader('X-VITI-Empresa','empresa-invalida')
            ->getJson('/api/v1/mi/apps/electrofrio/estado')
            ->assertStatus(422)
            ->assertJsonPath('message','Contexto de empresa inválido.');
    }

    public function test_invalid_payload_business_context_is_rejected(): void
    {
        $tenant=$this->createTenant('CTX-BAD-PAYLOAD');

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/estado?empresa_id=-10')
            ->assertStatus(422)
            ->assertJsonPath('message','Contexto de empresa inválido.');
    }
}
