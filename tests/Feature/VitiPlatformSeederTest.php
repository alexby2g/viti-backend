<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Usuario;
use Database\Seeders\VitiPlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VitiPlatformSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_electrofrio_seeder_preserves_existing_owner_and_client_assignment(): void
    {
        $cliente = \App\Models\Cliente::create([
            'nombre' => 'Cliente Electro Frío',
            'telefono' => '77770001',
            'correo' => 'electro@example.com',
            'ciudad' => 'Trinidad',
            'estado' => 'activo',
        ]);

        $empresa = Empresa::create([
            'cliente_id' => $cliente->id,
            'codigo' => 'EMP-EF-TEST',
            'nombre_comercial' => 'Electro Frío',
            'actividad' => 'Servicios técnicos',
            'estado' => 'activo',
        ]);

        $owner = Usuario::create([
            'cliente_id' => $cliente->id,
            'nombre' => 'Propietario Electro',
            'usuario' => 'electro_owner_test',
            'documento' => '99000001',
            'telefono' => '77770002',
            'correo' => 'owner@electro.test',
            'password' => 'Prueba123456',
            'rol' => 'cliente',
            'estado' => 'activo',
        ]);

        $empresa->usuarios()->attach($owner->id, [
            'rol_negocio' => 'propietario',
            'activo' => true,
        ]);

        $this->seed(VitiPlatformSeeder::class);

        $empresa->refresh();

        $this->assertSame($cliente->id, $empresa->cliente_id);
        $this->assertTrue($empresa->usuarios()->whereKey($owner->id)->wherePivot('rol_negocio', 'propietario')->exists());
    }
}
