<?php

namespace Tests\Feature;

use App\Models\{Cliente,Empresa,Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewRequestActiveCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_request_uses_active_company_header_when_client_has_multiple_companies(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $client = Cliente::create([
            'nombre'=>'Cliente contexto',
            'telefono'=>'70009991',
            'documento'=>'88999991',
            'ciudad'=>'Santa Cruz',
            'estado'=>'activo',
        ]);
        $user = Usuario::create([
            'cliente_id'=>$client->id,
            'nombre'=>$client->nombre,
            'usuario'=>'cliente_contexto',
            'documento'=>$client->documento,
            'telefono'=>$client->telefono,
            'password'=>'Prueba1234',
            'rol'=>'cliente',
            'estado'=>'activo',
        ]);
        Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-CONTEXT-A',
            'nombre_comercial'=>'Empresa A',
            'estado'=>'activo',
        ]);
        $companyB = Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-CONTEXT-B',
            'nombre_comercial'=>'Empresa B',
            'estado'=>'activo',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/mi/solicitud', [], ['X-VITI-Empresa'=>(string)$companyB->id])
            ->assertCreated()
            ->assertJsonPath('data.empresa.id',$companyB->id);

        $this->assertDatabaseHas('solicitudes_sistema', [
            'id'=>$response->json('data.id'),
            'empresa_id'=>$companyB->id,
            'cliente_id'=>$client->id,
        ]);
    }
}
