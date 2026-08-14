<?php

namespace Tests\Feature;

use App\Models\{PlanViti,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class SaasCapacityIntegrityTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_superadmin_provisioning_cannot_bypass_application_limit(): void
    {
        $tenant=$this->createTenant('SAAS-APP-LIMIT');
        $tenant['plan']->update(['max_aplicaciones'=>1]);
        $admin=$this->superadmin('saas_app_limit_admin','94440001');

        $this->actingAs($admin)
            ->postJson("/api/v1/saas/catalogo/{$tenant['catalog']->id}/provisionar",[
                'empresa_id'=>$tenant['company']->id,
                'version'=>'2.0.0',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message','Este negocio alcanzó el límite de aplicaciones de su plan VITI.');

        $this->assertSame(1,$tenant['company']->aplicaciones()->whereNotIn('estado',['retirado'])->count());
    }

    public function test_plan_downgrade_is_rejected_when_current_usage_exceeds_capacity(): void
    {
        $tenant=$this->createTenant('SAAS-DOWNGRADE');
        $admin=$this->superadmin('saas_downgrade_admin','94440002');
        $second=Usuario::create([
            'nombre'=>'Segundo usuario',
            'usuario'=>'segundo_usuario_downgrade',
            'documento'=>'94440102',
            'telefono'=>'74440102',
            'password'=>'Prueba1234',
            'rol'=>'cliente',
            'estado'=>'activo',
        ]);
        $tenant['company']->usuarios()->attach($second->id,[
            'rol_negocio'=>'empleado','permisos'=>json_encode(['inicio']),'activo'=>true,
        ]);
        $target=PlanViti::create([
            'codigo'=>'downgrade-test-plan',
            'nombre'=>'Plan downgrade test',
            'max_usuarios'=>1,
            'max_aplicaciones'=>1,
            'activo'=>true,
        ]);
        $originalPlanId=$tenant['company']->plan_viti_id;

        $this->actingAs($admin)
            ->putJson("/api/v1/saas/negocios/{$tenant['company']->id}/plan",[
                'plan_viti_id'=>$target->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message','El negocio ya tiene 2 usuarios activos y el plan permite 1.');

        $this->assertDatabaseHas('empresas',[
            'id'=>$tenant['company']->id,
            'plan_viti_id'=>$originalPlanId,
        ]);
    }

    private function superadmin(string $username,string $document): Usuario
    {
        return Usuario::create([
            'nombre'=>'Superadmin SaaS',
            'usuario'=>$username,
            'documento'=>$document,
            'telefono'=>'7'.substr($document,-8),
            'password'=>'Prueba1234',
            'rol'=>'superadmin',
            'estado'=>'activo',
        ]);
    }
}
