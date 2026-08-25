<?php

namespace Tests\Feature;

use App\Http\Controllers\EmpresaController;
use App\Models\{Cliente,Empresa,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EmpresaOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigning_owner_links_or_rejects_client_consistency_and_clearing_owner_keeps_employees(): void
    {
        $cliente = Cliente::create([
            'nombre' => 'Cliente Electro Frío',
            'telefono' => '70000001',
            'estado' => 'activo',
        ]);

        $empresa = Empresa::create([
            'codigo' => 'TEST-EF-OWN',
            'nombre_comercial' => 'Electro Frío',
            'estado' => 'activo',
        ]);

        $owner = Usuario::create([
            'cliente_id' => $cliente->id,
            'nombre' => 'Propietario',
            'usuario' => 'ef_propietario',
            'documento' => '700000001',
            'telefono' => '70000002',
            'password' => 'Prueba1234',
            'rol' => 'cliente',
            'estado' => 'activo',
        ]);

        $employee = Usuario::create([
            'cliente_id' => $cliente->id,
            'nombre' => 'Empleado',
            'usuario' => 'ef_empleado',
            'documento' => '700000003',
            'telefono' => '70000004',
            'password' => 'Prueba1234',
            'rol' => 'cliente',
            'estado' => 'activo',
        ]);

        $controller = app(EmpresaController::class);
        $assignRequest = Request::create('/api/v1/empresas/'.$empresa->id.'/propietario', 'POST', [
            'usuario_id' => $owner->id,
            'rol_negocio' => 'propietario',
        ]);

        $controller->assignUser($assignRequest, $empresa);
        $empresa->refresh();

        $this->assertSame($cliente->id, $empresa->cliente_id);
        $this->assertDatabaseHas('empresa_usuario', [
            'empresa_id' => $empresa->id,
            'usuario_id' => $owner->id,
            'rol_negocio' => 'propietario',
            'activo' => 1,
        ]);

        $employeeRequest = Request::create('/api/v1/empresas/'.$empresa->id.'/usuarios', 'POST', [
            'usuario_id' => $employee->id,
            'rol_negocio' => 'empleado',
        ]);
        $controller->assignUser($employeeRequest, $empresa);

        $clearOwnerRequest = Request::create('/api/v1/empresas/'.$empresa->id, 'PUT', [
            'nombre_comercial' => 'Electro Frío',
            'usuario_id' => null,
        ]);
        $controller->update($clearOwnerRequest, $empresa);

        $this->assertDatabaseMissing('empresa_usuario', [
            'empresa_id' => $empresa->id,
            'usuario_id' => $owner->id,
        ]);
        $this->assertDatabaseHas('empresa_usuario', [
            'empresa_id' => $empresa->id,
            'usuario_id' => $employee->id,
            'rol_negocio' => 'empleado',
            'activo' => 1,
        ]);
    }
}
