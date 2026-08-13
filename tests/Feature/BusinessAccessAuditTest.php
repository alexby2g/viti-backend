<?php

namespace Tests\Feature;

use App\Models\{Auditoria,Empresa,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class BusinessAccessAuditTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_updating_business_access_creates_an_audit_entry(): void
    {
        $tenant = $this->createTenant('ACCESS-AUDIT-A');
        $member = $this->attachBusinessUser($tenant['company'], 'MEMBER-A');
        $headers = ['X-VITI-Empresa'=>(string)$tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->putJson('/api/v1/mi/negocio/equipo/'.$member->id, [
                'rol_negocio'=>'administrador',
                'activo'=>true,
            ], $headers)
            ->assertOk();

        $audit = Auditoria::query()->where('accion','usuario_negocio_actualizado')->latest('id')->firstOrFail();
        $this->assertSame($tenant['user']->id, $audit->usuario_id);
        $this->assertSame($tenant['company']->id, $audit->empresa_id);
        $this->assertSame(Usuario::class, $audit->entidad_tipo);
        $this->assertSame($member->id, $audit->entidad_id);
        $this->assertSame($member->id, $audit->datos['usuario_id']);
        $this->assertSame('administrador', $audit->datos['rol_negocio']);
        $this->assertTrue($audit->datos['activo']);
        $this->assertFalse($audit->datos['password_actualizada']);
    }

    public function test_disabling_business_access_creates_an_audit_entry(): void
    {
        $tenant = $this->createTenant('ACCESS-AUDIT-B');
        $member = $this->attachBusinessUser($tenant['company'], 'MEMBER-B');
        $headers = ['X-VITI-Empresa'=>(string)$tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->deleteJson('/api/v1/mi/negocio/equipo/'.$member->id, [], $headers)
            ->assertNoContent();

        $audit = Auditoria::query()->where('accion','usuario_negocio_desactivado')->latest('id')->firstOrFail();
        $this->assertSame($tenant['user']->id, $audit->usuario_id);
        $this->assertSame($tenant['company']->id, $audit->empresa_id);
        $this->assertSame(Usuario::class, $audit->entidad_tipo);
        $this->assertSame($member->id, $audit->entidad_id);
        $this->assertSame('empleado', $audit->datos['rol_negocio']);
        $this->assertFalse($audit->datos['activo']);
    }

    private function attachBusinessUser(Empresa $company, string $suffix): Usuario
    {
        $seed = (string)abs(crc32($suffix));
        $user = Usuario::create([
            'nombre'=>'Usuario '.$suffix,
            'usuario'=>'audit_'.strtolower(str_replace('-','_',$suffix)),
            'documento'=>'5'.str_pad(substr($seed,0,9),9,'0'),
            'telefono'=>'6'.str_pad(substr((string)abs(crc32('phone-'.$suffix)),0,8),8,'0'),
            'password'=>'Prueba1234',
            'rol'=>'cliente',
            'estado'=>'activo',
        ]);
        $company->usuarios()->attach($user->id,[
            'rol_negocio'=>'empleado',
            'permisos'=>json_encode(['inicio','agenda','ordenes']),
            'activo'=>true,
        ]);
        return $user;
    }
}
