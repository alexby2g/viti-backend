<?php

namespace Tests\Feature;

use App\Models\{Cliente,Empresa,Usuario};
use Database\Seeders\VitiPlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VitiPlatformSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_electrofrio_seeder_preserves_existing_owner_and_client_assignment(): void
    {
        $cliente = Cliente::create([
            'nombre' => 'Cliente Electro Frío',
            'telefono' => '77770001',
            'correo' => 'electro@example.com',
            'ciudad' => 'Trinidad',
            'estado' => 'activo',
        ]);

        // The migration suite may already provision Electro Frío. Reuse it and
        // explicitly prepare the desired real assignment before rerunning the seeder.
        $empresa = Empresa::query()->where('nombre_comercial', 'Electro Frío')->firstOrFail();
        $empresa->update(['cliente_id' => $cliente->id, 'estado' => 'activo']);

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

        DB::table('empresa_usuario')->where('empresa_id', $empresa->id)->delete();
        $empresa->usuarios()->syncWithoutDetaching([
            $owner->id => [
                'rol_negocio' => 'propietario',
                'activo' => true,
            ],
        ]);

        $this->seed(VitiPlatformSeeder::class);

        $empresa->refresh();

        $this->assertSame($cliente->id, $empresa->cliente_id);
        $this->assertTrue($empresa->usuarios()->whereKey($owner->id)->wherePivot('rol_negocio', 'propietario')->exists());
    }
}
