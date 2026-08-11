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
        $admin=Usuario::create([
            'nombre'=>'Admin Ciclo','usuario'=>'admin_ciclo','documento'=>'90000001','telefono'=>'70000001',
            'password'=>'PruebaSegura123','rol'=>'administrador','estado'=>'activo',
        ]);
        $client=Cliente::create(['nombre'=>'Cliente Ciclo','telefono'=>'71111111','estado'=>'informacion_recibida']);
        $company=Empresa::create(['cliente_id'=>$client->id,'codigo'=>'EMP-CICLO','nombre_comercial'=>'Empresa Ciclo','estado'=>'activo']);
        $app=Aplicacion::create([
            'empresa_id'=>$company->id,'nombre'=>'Sistema Ciclo','slug'=>'sistema-ciclo','version'=>'0.8.0',
            'tipo'=>'web','entorno'=>'beta','estado'=>'en_pruebas','acceso_cliente'=>false,
        ]);

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
}
