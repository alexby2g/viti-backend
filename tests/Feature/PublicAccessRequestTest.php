<?php

namespace Tests\Feature;

use App\Models\{Cliente,Empresa,SolicitudSistema,Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAccessRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_access_request_creates_a_reviewable_draft_without_creating_an_account(): void
    {
        $this->seed(CuestionarioSeeder::class);

        $response = $this->postJson('/api/v1/publico/solicitudes', [
            'nombre' => 'María Pérez',
            'correo' => 'maria@example.com',
            'telefono' => '70012345',
            'whatsapp' => '70012345',
            'ciudad' => 'Santa Cruz',
            'empresa_nombre' => 'Clima Servicios',
            'empresa_actividad' => 'Mantenimiento de aire acondicionado',
            'titulo_sistema' => 'Organizar citas y servicios técnicos',
            'resumen' => 'Necesito controlar clientes, equipos, visitas y garantías.',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.estado', 'recibida')
            ->assertJsonMissingPath('data.solicitud_token')
            ->assertJsonMissingPath('data.ruta_cuestionario');

        $client = Cliente::query()->where('telefono', '70012345')->firstOrFail();
        $company = Empresa::query()->where('cliente_id', $client->id)->firstOrFail();
        $request = SolicitudSistema::query()->where('empresa_id', $company->id)->firstOrFail();

        $this->assertSame('prospecto', $client->estado);
        $this->assertSame('maria@example.com', $client->correo);
        $this->assertSame('pendiente_revision', $company->estado);
        $this->assertSame('borrador', $request->estado);
        $this->assertSame('Organizar citas y servicios técnicos', $request->titulo);
        $this->assertDatabaseMissing('usuarios', ['telefono' => '70012345']);
        $this->assertSame(0, Usuario::query()->where('telefono', '70012345')->count());
    }
}
