<?php

namespace Tests\Feature;

use App\Models\{Conversacion,ElectrofrioCliente,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioCustomerChatTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_final_customer_and_business_share_an_isolated_channel(): void
    {
        $tenant = $this->createTenant('CHAT');
        $customer = ElectrofrioCliente::create(['empresa_id'=>$tenant['company']->id,'nombre'=>'Cliente final CHAT','telefono'=>'61122334','activo'=>true]);
        $headers = ['X-VITI-Empresa'=>(string)$tenant['company']->id];
        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/clientes/{$customer->id}/acceso", ['usuario'=>'final_chat','documento'=>'812345678','telefono'=>$customer->telefono,'password'=>'Prueba1234','password_confirmation'=>'Prueba1234'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.usuario', 'final_chat');
        $final = ['customer'=>$customer,'user'=>Usuario::query()->where('electrofrio_cliente_id',$customer->id)->firstOrFail()];

        $customerResponse = $this->actingAs($final['user'])
            ->getJson('/api/v1/portal/electrofrio/buzon')
            ->assertOk()
            ->assertJsonPath('data.0.contexto', 'electrofrio')
            ->assertJsonPath('data.0.electrofrio_cliente_id', $final['customer']->id);
        $conversationId = (int) $customerResponse->json('data.0.id');

        $this->actingAs($final['user'])
            ->postJson("/api/v1/portal/electrofrio/buzon/{$conversationId}/mensajes", ['mensaje'=>'Necesito revisar mi equipo'])
            ->assertCreated()
            ->assertJsonPath('data.es_mio', true)
            ->assertJsonPath('data.es_cliente_final', true);

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/buzon', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonPath('data.0.contacto.nombre', $final['customer']->nombre);

        $this->actingAs($tenant['user'])
            ->postJson("/api/v1/mi/apps/electrofrio/buzon/{$conversationId}/mensajes", ['mensaje'=>'La visita quedó agendada'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.es_mio', true)
            ->assertJsonPath('data.es_cliente_final', false);

        $this->actingAs($final['user'])
            ->getJson("/api/v1/portal/electrofrio/buzon/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('data.mensajes.1.es_mio', false)
            ->assertJsonPath('data.mensajes.1.es_cliente_final', false);

        $admin = Usuario::create(['nombre'=>'Admin prueba','usuario'=>'admin_chat','documento'=>'765432109','password'=>'Prueba1234','rol'=>'superadmin','estado'=>'activo']);
        $this->actingAs($admin)
            ->getJson('/api/v1/buzon')
            ->assertOk()
            ->assertJsonMissing(['id'=>$conversationId,'contexto'=>'electrofrio']);

        $this->assertDatabaseHas('conversaciones', ['id'=>$conversationId,'empresa_id'=>$tenant['company']->id,'electrofrio_cliente_id'=>$final['customer']->id,'canal_principal'=>true]);
        $this->assertSame(1, Conversacion::query()->where('contexto','electrofrio')->where('canal_principal',true)->count());

        $this->actingAs($tenant['user'])
            ->deleteJson("/api/v1/mi/apps/electrofrio/clientes/{$customer->id}/acceso", [], $headers)
            ->assertOk();
        $this->actingAs($final['user']->fresh())
            ->getJson('/api/v1/portal/electrofrio/buzon')
            ->assertForbidden();
        $this->assertDatabaseHas('conversaciones', ['id'=>$conversationId,'canal_principal'=>true]);
    }
}
