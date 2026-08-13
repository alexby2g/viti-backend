<?php

namespace Tests\Feature;

use App\Models\{Empresa,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class BusinessOwnerProtectionTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_business_admin_cannot_demote_an_owner(): void
    {
        $tenant = $this->createTenant('OWNER-GUARD-A');
        $admin = $this->attachBusinessUser($tenant['company'], 'ADMIN-A', 'administrador');
        $headers = ['X-VITI-Empresa'=>(string)$tenant['company']->id];

        $this->actingAs($admin)
            ->putJson('/api/v1/mi/negocio/equipo/'.$tenant['user']->id, [
                'rol_negocio'=>'administrador',
                'activo'=>true,
            ], $headers)
            ->assertForbidden();

        $membership = $tenant['company']->usuarios()->where('usuarios.id',$tenant['user']->id)->firstOrFail();
        $this->assertSame('propietario', $membership->pivot?->rol_negocio);
        $this->assertTrue((bool)$membership->pivot?->activo);
    }

    public function test_business_admin_cannot_remove_an_owner(): void
    {
        $tenant = $this->createTenant('OWNER-GUARD-B');
        $admin = $this->attachBusinessUser($tenant['company'], 'ADMIN-B', 'administrador');
        $headers = ['X-VITI-Empresa'=>(string)$tenant['company']->id];

        $this->actingAs($admin)
            ->deleteJson('/api/v1/mi/negocio/equipo/'.$tenant['user']->id, [], $headers)
            ->assertForbidden();

        $membership = $tenant['company']->usuarios()->where('usuarios.id',$tenant['user']->id)->firstOrFail();
        $this->assertTrue((bool)$membership->pivot?->activo);
    }

    public function test_owner_can_manage_a_regular_business_admin(): void
    {
        $tenant = $this->createTenant('OWNER-GUARD-C');
        $admin = $this->attachBusinessUser($tenant['company'], 'ADMIN-C', 'administrador');
        $headers = ['X-VITI-Empresa'=>(string)$tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->putJson('/api/v1/mi/negocio/equipo/'.$admin->id, [
                'rol_negocio'=>'empleado',
                'activo'=>true,
            ], $headers)
            ->assertOk();

        $membership = $tenant['company']->usuarios()->where('usuarios.id',$admin->id)->firstOrFail();
        $this->assertSame('empleado', $membership->pivot?->rol_negocio);
        $this->assertTrue((bool)$membership->pivot?->activo);
    }

    public function test_business_must_keep_at_least_one_active_owner(): void
    {
        $tenant = $this->createTenant('OWNER-GUARD-D');
        $headers = ['X-VITI-Empresa'=>(string)$tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->putJson('/api/v1/mi/negocio/equipo/'.$tenant['user']->id, [
                'rol_negocio'=>'administrador',
                'activo'=>true,
            ], $headers)
            ->assertStatus(422);

        $membership = $tenant['company']->usuarios()->where('usuarios.id',$tenant['user']->id)->firstOrFail();
        $this->assertSame('propietario', $membership->pivot?->rol_negocio);
        $this->assertTrue((bool)$membership->pivot?->activo);
    }

    private function attachBusinessUser(Empresa $company, string $suffix, string $role): Usuario
    {
        $seed = (string)abs(crc32($suffix));
        $user = Usuario::create([
            'nombre'=>'Usuario '.$suffix,
            'usuario'=>'guard_'.strtolower(str_replace('-','_',$suffix)),
            'documento'=>'6'.str_pad(substr($seed,0,9),9,'0'),
            'telefono'=>'7'.str_pad(substr((string)abs(crc32('phone-'.$suffix)),0,8),8,'0'),
            'password'=>'Prueba1234',
            'rol'=>'cliente',
            'estado'=>'activo',
        ]);
        $company->usuarios()->attach($user->id,[
            'rol_negocio'=>$role,
            'permisos'=>null,
            'activo'=>true,
        ]);
        return $user;
    }
}
