<?php

namespace Tests\Feature;

use App\Models\{Aplicacion,Cliente,Empresa,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationLifecycleControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_change_cycle_without_delivering_app(): void
    {
        [$admin,$app]=$this->fixture();

        $this->actingAs($admin)
            ->putJson("/api/v1/aplicaciones/{$app->id}/ciclo",['entorno'=>'produccion','estado'=>'activo'])
            ->assertOk()
            ->assertJsonPath('data.entorno','produccion')
            ->assertJsonPath('data.estado','activo')
            ->assertJsonPath('data.acceso_cliente',false);

        $this->assertDatabaseHas('aplicaciones',[
            'id'=>$app->id,'entorno'=>'produccion','estado'=>'activo','acceso_cliente'=>false,
        ]);
    }

    public function test_native_client_can_use_post_cycle_then_admin_can_deliver_and_revoke_access(): void
    {
        [$admin,$app]=$this->fixture('native');

        $this->actingAs($admin)
            ->postJson("/api/v1/aplicaciones/{$app->id}/ciclo",['entorno'=>'produccion','estado'=>'activo'])
            ->assertOk()
            ->assertJsonPath('data.entorno','produccion')
            ->assertJsonPath('data.estado','activo');

        $this->actingAs($admin)
            ->postJson("/api/v1/aplicaciones/{$app->id}/entregar")
            ->assertOk()
            ->assertJsonPath('data.acceso_cliente',true);

        $this->assertDatabaseHas('aplicaciones',['id'=>$app->id,'acceso_cliente'=>true]);

        $this->actingAs($admin)
            ->postJson("/api/v1/aplicaciones/{$app->id}/revocar")
            ->assertOk()
            ->assertJsonPath('data.acceso_cliente',false);

        $this->assertDatabaseHas('aplicaciones',['id'=>$app->id,'acceso_cliente'=>false]);
    }

    public function test_delivery_requires_active_production_cycle(): void
    {
        [$admin,$app]=$this->fixture('blocked');

        $this->actingAs($admin)
            ->postJson("/api/v1/aplicaciones/{$app->id}/entregar")
            ->assertStatus(422);

        $this->assertDatabaseHas('aplicaciones',['id'=>$app->id,'acceso_cliente'=>false]);
    }

    public function test_general_update_cannot_bypass_cycle_or_delivery_controls(): void
    {
        [$admin,$app]=$this->fixture('general-update');

        $this->actingAs($admin)
            ->putJson("/api/v1/aplicaciones/{$app->id}",[
                'empresa_id'=>$app->empresa_id,
                'nombre'=>'Sistema editado sin entregar',
                'entorno'=>'produccion',
                'estado'=>'activo',
                'acceso_cliente'=>true,
            ])
            ->assertOk()
            ->assertJsonPath('data.nombre','Sistema editado sin entregar')
            ->assertJsonPath('data.entorno','beta')
            ->assertJsonPath('data.estado','en_pruebas')
            ->assertJsonPath('data.acceso_cliente',false);

        $this->assertDatabaseHas('aplicaciones',[
            'id'=>$app->id,
            'nombre'=>'Sistema editado sin entregar',
            'entorno'=>'beta',
            'estado'=>'en_pruebas',
            'acceso_cliente'=>false,
        ]);
    }

    public function test_delivered_application_cannot_return_to_beta_or_testing(): void
    {
        [$admin,$app]=$this->fixture('delivered-lock');

        $this->actingAs($admin)
            ->postJson("/api/v1/aplicaciones/{$app->id}/ciclo",['entorno'=>'produccion','estado'=>'activo'])
            ->assertOk();
        $this->actingAs($admin)
            ->postJson("/api/v1/aplicaciones/{$app->id}/entregar")
            ->assertOk();

        $this->actingAs($admin)
            ->putJson("/api/v1/aplicaciones/{$app->id}/ciclo",['entorno'=>'beta','estado'=>'en_pruebas'])
            ->assertStatus(422);

        $this->assertDatabaseHas('aplicaciones',[
            'id'=>$app->id,
            'entorno'=>'produccion',
            'estado'=>'activo',
            'acceso_cliente'=>true,
        ]);
    }

    public function test_retired_application_cannot_be_reactivated_from_normal_cycle(): void
    {
        [$admin,$app]=$this->fixture('retired-lock');

        $this->actingAs($admin)
            ->putJson("/api/v1/aplicaciones/{$app->id}/ciclo",['entorno'=>'beta','estado'=>'retirado'])
            ->assertOk();

        $this->actingAs($admin)
            ->putJson("/api/v1/aplicaciones/{$app->id}/ciclo",['entorno'=>'produccion','estado'=>'activo'])
            ->assertStatus(422);

        $this->assertDatabaseHas('aplicaciones',[
            'id'=>$app->id,
            'entorno'=>'beta',
            'estado'=>'retirado',
        ]);
    }

    private function fixture(string $suffix='cycle'): array
    {
        $admin=Usuario::create([
            'nombre'=>'Admin Ciclo','usuario'=>'admin_'.$suffix,'documento'=>'90'.str_pad((string)random_int(1,999999),6,'0',STR_PAD_LEFT),
            'telefono'=>'70'.str_pad((string)random_int(1,999999),6,'0',STR_PAD_LEFT),
            'password'=>'PruebaSegura123','rol'=>'administrador','estado'=>'activo',
        ]);
        $client=Cliente::create(['nombre'=>'Cliente Ciclo','telefono'=>'71'.str_pad((string)random_int(1,999999),6,'0',STR_PAD_LEFT),'estado'=>'informacion_recibida']);
        $company=Empresa::create(['cliente_id'=>$client->id,'codigo'=>'EMP-'.strtoupper($suffix),'nombre_comercial'=>'Empresa Ciclo '.$suffix,'estado'=>'activo']);
        $app=Aplicacion::create([
            'empresa_id'=>$company->id,'nombre'=>'Sistema Ciclo '.$suffix,'slug'=>'sistema-ciclo-'.$suffix,'version'=>'0.8.0',
            'tipo'=>'web','entorno'=>'beta','estado'=>'en_pruebas','acceso_cliente'=>false,
        ]);

        return [$admin,$app];
    }
}
