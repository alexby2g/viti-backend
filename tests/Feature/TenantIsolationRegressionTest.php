<?php

namespace Tests\Feature;

use App\Models\{Archivo,Cliente,Conversacion,Proyecto};
use App\Services\ChatChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\{CreatesVitiTenants,TestCase};

class TenantIsolationRegressionTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    private function attachClient(array $tenant, string $suffix): Cliente
    {
        $cliente = Cliente::create([
            'nombre' => 'Cliente '.$suffix,
            'telefono' => '7'.substr(str_pad((string) abs(crc32('cliente-phone-'.$suffix)), 8, '0', STR_PAD_LEFT), 0, 8),
            'estado' => 'activo',
        ]);

        $tenant['user']->update(['cliente_id' => $cliente->id]);

        return $cliente;
    }

    public function test_user_cannot_switch_active_company_to_an_unrelated_tenant(): void
    {
        $tenantA = $this->createTenant('ISOLATION-A');
        $tenantB = $this->createTenant('ISOLATION-B');

        $this->actingAs($tenantA['user'])
            ->getJson('/api/v1/mi/negocio/equipo', [
                'X-VITI-Empresa' => (string) $tenantB['company']->id,
            ])
            ->assertStatus(403);
    }

    public function test_user_cannot_manage_a_business_team_in_an_unrelated_tenant(): void
    {
        $tenantA = $this->createTenant('MANAGE-A');
        $tenantB = $this->createTenant('MANAGE-B');

        $member = $this->createTenant('MEMBER-B', 'empleado')['user'];

        $this->actingAs($tenantA['user'])
            ->putJson('/api/v1/mi/negocio/equipo/'.$member->id, [
                'rol_negocio' => 'administrador',
                'activo' => true,
            ], [
                'X-VITI-Empresa' => (string) $tenantB['company']->id,
            ])
            ->assertStatus(403);
    }

    public function test_user_cannot_list_apps_from_an_unrelated_tenant(): void
    {
        $tenantA = $this->createTenant('APPS-A');
        $tenantB = $this->createTenant('APPS-B');

        $this->actingAs($tenantA['user'])
            ->getJson('/api/v1/mi/aplicaciones', [
                'X-VITI-Empresa' => (string) $tenantB['company']->id,
            ])
            ->assertStatus(403);
    }

    public function test_user_cannot_read_project_data_from_an_unrelated_tenant(): void
    {
        $tenantA = $this->createTenant('PROJECT-A');
        $tenantB = $this->createTenant('PROJECT-B');
        $clienteB = $this->attachClient($tenantB, 'PROJECT-B');

        Proyecto::create([
            'empresa_id' => $tenantB['company']->id,
            'cliente_id' => $clienteB->id,
            'codigo' => 'PRO-B-CROSS-TENANT',
            'nombre' => 'Proyecto ajeno',
            'fase' => 'levantamiento',
            'estado' => 'activo',
            'progreso' => 10,
        ]);

        $this->actingAs($tenantA['user'])
            ->getJson('/api/v1/mi/proyecto', [
                'X-VITI-Empresa' => (string) $tenantB['company']->id,
            ])
            ->assertStatus(403);
    }

    public function test_client_cannot_read_a_private_viti_conversation_from_an_unrelated_client(): void
    {
        $tenantA = $this->createTenant('CHAT-A');
        $tenantB = $this->createTenant('CHAT-B');
        $this->attachClient($tenantA, 'CHAT-A');
        $clienteB = $this->attachClient($tenantB, 'CHAT-B');

        $conversation = Conversacion::create([
            'cliente_id' => $clienteB->id,
            'responsable_usuario_id' => null,
            'asunto' => 'Conversación privada B',
            'estado' => 'abierta',
            'contexto' => ChatChannelService::VITI,
            'canal_principal' => true,
        ]);

        $this->actingAs($tenantA['user'])
            ->getJson('/api/v1/mi/buzon/'.$conversation->id)
            ->assertStatus(403);
    }

    public function test_client_cannot_download_a_private_file_from_an_unrelated_client(): void
    {
        Storage::fake('private_uploads');

        $tenantA = $this->createTenant('FILE-A');
        $tenantB = $this->createTenant('FILE-B');
        $this->attachClient($tenantA, 'FILE-A');
        $clienteB = $this->attachClient($tenantB, 'FILE-B');
        $path = 'archivos/test/private-b.txt';
        Storage::disk('private_uploads')->put($path, 'private tenant B');

        $archivo = Archivo::create([
            'adjuntable_type' => Cliente::class,
            'adjuntable_id' => $clienteB->id,
            'subido_por' => $tenantB['user']->id,
            'categoria' => 'general',
            'nombre_original' => 'private-b.txt',
            'ruta' => $path,
            'mime' => 'text/plain',
            'tamano' => strlen('private tenant B'),
            'descripcion' => 'Documento privado de B',
        ]);

        $this->actingAs($tenantA['user'])
            ->get('/api/v1/mi/archivos/'.$archivo->id.'/descargar')
            ->assertStatus(403);
    }
}
