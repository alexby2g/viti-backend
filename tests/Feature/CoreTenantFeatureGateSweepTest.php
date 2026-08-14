<?php

namespace Tests\Feature;

use App\Models\ElectrofrioCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class CoreTenantFeatureGateSweepTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_user_from_company_a_cannot_select_company_b_context(): void
    {
        $a=$this->createTenant('SWEEP-A');
        $b=$this->createTenant('SWEEP-B');

        $this->actingAs($a['user'])
            ->withHeader('X-VITI-Empresa',(string)$b['company']->id)
            ->getJson('/api/v1/mi/apps/electrofrio/estado')
            ->assertForbidden();
    }

    public function test_guessed_record_id_from_another_company_is_not_accessible(): void
    {
        $a=$this->createTenant('SWEEP-C');
        $b=$this->createTenant('SWEEP-D');
        $foreign=ElectrofrioCliente::create([
            'empresa_id'=>$b['company']->id,
            'nombre'=>'Cliente secreto B',
            'telefono'=>'68887777',
            'activo'=>true,
        ]);

        $this->actingAs($a['user'])
            ->withHeader('X-VITI-Empresa',(string)$a['company']->id)
            ->putJson('/api/v1/mi/apps/electrofrio/clientes/'.$foreign->id,[
                'nombre'=>'Intento de modificación',
                'telefono'=>'68887777',
                'activo'=>true,
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('electrofrio_clientes',[
            'id'=>$foreign->id,
            'empresa_id'=>$b['company']->id,
            'nombre'=>'Cliente secreto B',
        ]);
    }

    public function test_feature_gate_blocks_direct_url_to_module_not_in_plan(): void
    {
        $tenant=$this->createTenant('SWEEP-E');
        $tenant['plan']->update(['modulos'=>['inicio','ordenes']]);
        $tenant['company']->refresh();

        $this->actingAs($tenant['user'])
            ->withHeader('X-VITI-Empresa',(string)$tenant['company']->id)
            ->getJson('/api/v1/mi/apps/electrofrio/clientes')
            ->assertForbidden();

        $this->actingAs($tenant['user'])
            ->withHeader('X-VITI-Empresa',(string)$tenant['company']->id)
            ->getJson('/api/v1/mi/apps/electrofrio/estado')
            ->assertOk()
            ->assertJsonPath('data.plan.modulos.0','inicio')
            ->assertJsonPath('data.plan.modulos.1','ordenes');
    }

    public function test_employee_permissions_are_intersected_with_plan_modules(): void
    {
        $tenant=$this->createTenant('SWEEP-F','tecnico',['inicio','ordenes','clientes']);
        $tenant['plan']->update(['modulos'=>['inicio','ordenes']]);

        $response=$this->actingAs($tenant['user'])
            ->withHeader('X-VITI-Empresa',(string)$tenant['company']->id)
            ->getJson('/api/v1/mi/apps/electrofrio/estado')
            ->assertOk();

        $this->assertSame(['inicio','ordenes'],$response->json('data.plan.modulos'));
    }
}
