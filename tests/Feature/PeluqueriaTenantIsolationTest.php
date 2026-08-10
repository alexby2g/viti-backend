<?php

namespace Tests\Feature;

use App\Models\{Aplicacion,CatalogoAplicacion,Cliente,Empresa,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeluqueriaTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_only_sees_businesses_with_peluqueria_app(): void
    {
        $admin = Usuario::create([
            'nombre'=>'Admin Plataforma','usuario'=>'admin_peluqueria_test','documento'=>'91000001',
            'telefono'=>'71000001','password'=>'Prueba1234','rol'=>'superadmin','estado'=>'activo',
        ]);
        $client = Cliente::create(['nombre'=>'Cliente Test','telefono'=>'72000001','estado'=>'informacion_recibida']);
        $hair = Empresa::create(['cliente_id'=>$client->id,'codigo'=>'EMP-HAIR','nombre_comercial'=>'Salón Correcto','estado'=>'activo']);
        $other = Empresa::create(['cliente_id'=>$client->id,'codigo'=>'EMP-OTHER','nombre_comercial'=>'Soporte Técnico','estado'=>'activo']);
        $catalog = CatalogoAplicacion::create([
            'clave'=>'peluqueria','nombre'=>'Peluquería','descripcion'=>'App de peluquería','icono'=>'content_cut',
            'tipo'=>'web','ruta_base'=>'/apps/peluqueria','activo'=>true,'solicitable'=>false,'orden'=>10,
        ]);
        Aplicacion::create([
            'empresa_id'=>$hair->id,'catalogo_aplicacion_id'=>$catalog->id,'nombre'=>'Peluquería VITI',
            'slug'=>'peluqueria-test','version'=>'1.0','tipo'=>'web','entorno'=>'produccion','estado'=>'activo','acceso_cliente'=>true,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/apps/peluqueria/empresas');
        $response->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$hair->id);
        $this->assertStringNotContainsString('Soporte Técnico',$response->getContent());
    }

    public function test_admin_cannot_use_peluqueria_endpoints_with_unrelated_business(): void
    {
        $admin = Usuario::create([
            'nombre'=>'Admin Plataforma','usuario'=>'admin_peluqueria_guard','documento'=>'91000002',
            'telefono'=>'71000002','password'=>'Prueba1234','rol'=>'superadmin','estado'=>'activo',
        ]);
        $client = Cliente::create(['nombre'=>'Cliente Test','telefono'=>'72000002','estado'=>'informacion_recibida']);
        $other = Empresa::create(['cliente_id'=>$client->id,'codigo'=>'EMP-NOHAIR','nombre_comercial'=>'Soporte Vital PC','estado'=>'activo']);

        $this->actingAs($admin)
            ->getJson('/api/v1/apps/peluqueria/resumen?empresa_id='.$other->id)
            ->assertStatus(422)
            ->assertJsonPath('message','La empresa seleccionada no tiene Peluquería VITI habilitada.');
    }
}
