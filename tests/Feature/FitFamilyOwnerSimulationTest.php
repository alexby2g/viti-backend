<?php

namespace Tests\Feature;

use App\Models\{Aplicacion,Cliente,Empresa,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FitFamilyOwnerSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fitfamily_owner_simulation_creates_unassigned_company_then_assigns_owner(): void
    {
        $password = (string) env('VITI_SIM_PASSWORD', '');
        if ($password === '') {
            $this->markTestSkipped('Define VITI_SIM_PASSWORD para ejecutar la simulación de credenciales.');
        }

        $client = Cliente::create([
            'nombre' => 'Carlos Enrique Guzmán Ribera',
            'telefono' => '700000102',
            'correo' => 'fitfamily-owner@example.test',
            'ciudad' => 'Trinidad',
            'estado' => 'informacion_recibida',
        ]);

        // La empresa nace sin plan y sin dueño: el cuestionario/revisión comercial decide después.
        $company = Empresa::create([
            'cliente_id' => $client->id,
            'plan_viti_id' => null,
            'codigo' => 'EMP-FITFAMILY-SIM',
            'nombre_comercial' => 'FitFamily',
            'actividad' => 'Venta de alimentos y productos de nutrición',
            'estado' => 'pendiente_revision',
        ]);

        $application = Aplicacion::create([
            'empresa_id' => $company->id,
            'nombre' => 'FitFamily',
            'slug' => 'fitfamily-sim',
            'descripcion' => 'Catálogo y pedidos de alimentos.',
            'tipo' => 'web',
            'entorno' => 'beta',
            'estado' => 'en_pruebas',
            'acceso_cliente' => false,
            'configuracion' => ['origen' => 'simulacion_fitfamily'],
        ]);

        $this->assertDatabaseMissing('empresa_usuario', [
            'empresa_id' => $company->id,
        ]);

        $owner = Usuario::create([
            'cliente_id' => $client->id,
            'nombre' => 'Carlos Enrique Guzmán Ribera',
            'apellido' => '',
            'usuario' => 'enrique14',
            'documento' => 'SIM-FITFAMILY-001',
            'telefono' => '700000102',
            'correo' => 'fitfamily-owner@example.test',
            'password' => $password,
            'rol' => 'cliente',
            'estado' => 'activo',
        ]);

        $company->usuarios()->attach($owner->id, [
            'rol_negocio' => 'propietario',
            'permisos' => json_encode(['*']),
            'activo' => true,
        ]);

        $application->usuarios()->attach($owner->id, [
            'rol' => 'propietario',
            'permisos' => json_encode(['*']),
            'activo' => true,
        ]);

        $this->assertTrue(Hash::check($password, $owner->password));
        $this->assertDatabaseHas('empresa_usuario', [
            'empresa_id' => $company->id,
            'usuario_id' => $owner->id,
            'rol_negocio' => 'propietario',
            'activo' => 1,
        ]);
        $this->assertDatabaseHas('aplicacion_usuario', [
            'aplicacion_id' => $application->id,
            'usuario_id' => $owner->id,
            'rol' => 'propietario',
            'activo' => 1,
        ]);
    }
}
