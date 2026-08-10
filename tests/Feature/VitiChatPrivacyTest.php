<?php

namespace Tests\Feature;

use App\Models\{Cliente,Conversacion,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VitiChatPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_secondary_platform_admin_does_not_inherit_private_viti_inbox(): void
    {
        $owner = Usuario::create([
            'nombre'=>'Alex Principal','usuario'=>'alex_privacy','documento'=>'93000001','telefono'=>'73000001',
            'password'=>'Prueba1234','rol'=>'superadmin','estado'=>'activo',
        ]);
        $secondary = Usuario::create([
            'nombre'=>'Admin Secundario','usuario'=>'admin_privacy','documento'=>'93000002','telefono'=>'73000002',
            'password'=>'Prueba1234','rol'=>'administrador','estado'=>'activo',
        ]);
        $client = Cliente::create(['nombre'=>'Empresa Privada','telefono'=>'73000003','estado'=>'informacion_recibida']);
        $conversation = Conversacion::create([
            'cliente_id'=>$client->id,
            'responsable_usuario_id'=>$owner->id,
            'asunto'=>'Atención VITI',
            'estado'=>'abierta',
            'contexto'=>'viti',
            'canal_principal'=>true,
        ]);

        $this->actingAs($secondary)
            ->getJson('/api/v1/buzon')
            ->assertForbidden();

        $this->actingAs($secondary)
            ->getJson('/api/v1/buzon/'.$conversation->id)
            ->assertForbidden();

        $this->actingAs($secondary)
            ->getJson('/api/v1/notificaciones/buzon')
            ->assertOk()
            ->assertJsonPath('data.no_leidos',0)
            ->assertJsonCount(0,'data.items');

        $this->actingAs($owner)
            ->getJson('/api/v1/buzon/'.$conversation->id)
            ->assertOk()
            ->assertJsonPath('data.id',$conversation->id);
    }
}
