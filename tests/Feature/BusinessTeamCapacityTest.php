<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class BusinessTeamCapacityTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_inactive_member_cannot_be_reactivated_when_plan_is_at_capacity(): void
    {
        $tenant=$this->createTenant('TEAM-CAPACITY');
        $tenant['plan']->update(['max_usuarios'=>1]);
        $inactive=Usuario::create([
            'nombre'=>'Usuario inactivo',
            'usuario'=>'usuario_inactivo_capacity',
            'documento'=>'65432188',
            'telefono'=>'71000188',
            'password'=>'Prueba1234',
            'rol'=>'cliente',
            'estado'=>'activo',
        ]);
        $tenant['company']->usuarios()->attach($inactive->id,[
            'rol_negocio'=>'empleado',
            'permisos'=>json_encode(['inicio']),
            'activo'=>false,
        ]);
        $headers=['X-VITI-Empresa'=>(string)$tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->putJson("/api/v1/mi/negocio/equipo/{$inactive->id}",[
                'rol_negocio'=>'empleado',
                'activo'=>true,
                'permisos'=>['inicio'],
            ],$headers)
            ->assertStatus(422)
            ->assertJsonPath('message','Este negocio alcanzó el límite de usuarios de su plan VITI.');

        $this->assertDatabaseHas('empresa_usuario',[
            'empresa_id'=>$tenant['company']->id,
            'usuario_id'=>$inactive->id,
            'activo'=>0,
        ]);
    }

    public function test_inactive_member_can_be_reactivated_when_capacity_exists(): void
    {
        $tenant=$this->createTenant('TEAM-AVAILABLE');
        $tenant['plan']->update(['max_usuarios'=>2]);
        $inactive=Usuario::create([
            'nombre'=>'Usuario recuperado',
            'usuario'=>'usuario_recuperado_capacity',
            'documento'=>'65432189',
            'telefono'=>'71000189',
            'password'=>'Prueba1234',
            'rol'=>'cliente',
            'estado'=>'activo',
        ]);
        $tenant['company']->usuarios()->attach($inactive->id,[
            'rol_negocio'=>'empleado',
            'permisos'=>json_encode(['inicio']),
            'activo'=>false,
        ]);
        $headers=['X-VITI-Empresa'=>(string)$tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->putJson("/api/v1/mi/negocio/equipo/{$inactive->id}",[
                'rol_negocio'=>'empleado',
                'activo'=>true,
                'permisos'=>['inicio'],
            ],$headers)
            ->assertOk();

        $this->assertDatabaseHas('empresa_usuario',[
            'empresa_id'=>$tenant['company']->id,
            'usuario_id'=>$inactive->id,
            'activo'=>1,
        ]);
    }
}
