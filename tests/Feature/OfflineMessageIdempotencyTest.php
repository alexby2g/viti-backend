<?php

namespace Tests\Feature;

use App\Models\{Cliente,Conversacion,Mensaje,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineMessageIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_retrying_same_offline_message_creates_only_one_record(): void
    {
        $client=Cliente::create([
            'nombre'=>'Cliente Offline',
            'telefono'=>'73991111',
            'estado'=>'informacion_recibida',
        ]);
        $user=Usuario::create([
            'cliente_id'=>$client->id,
            'nombre'=>'Cliente Offline',
            'usuario'=>'cliente_offline_idempotente',
            'documento'=>'88776655',
            'telefono'=>'73991111',
            'password'=>'Prueba1234',
            'rol'=>'cliente',
            'estado'=>'activo',
        ]);
        $conversation=Conversacion::create([
            'cliente_id'=>$client->id,
            'asunto'=>'Atención VITI',
            'estado'=>'abierta',
            'contexto'=>'viti',
            'canal_principal'=>true,
        ]);
        $requestId=(string)Str::uuid();
        $payload=['mensaje'=>'Mensaje escrito sin Internet.','client_request_id'=>$requestId];

        $first=$this->actingAs($user)->postJson("/api/v1/mi/buzon/{$conversation->id}/mensajes",$payload);
        $first->assertSuccessful()->assertJsonPath('data.client_request_id',$requestId);
        $firstId=$first->json('data.id');

        $second=$this->actingAs($user)->postJson("/api/v1/mi/buzon/{$conversation->id}/mensajes",$payload);
        $second->assertSuccessful()->assertJsonPath('data.id',$firstId)->assertJsonPath('data.client_request_id',$requestId);

        $this->assertSame(1,Mensaje::query()->where('client_request_id',$requestId)->count());
        $this->assertDatabaseHas('mensajes',[
            'id'=>$firstId,
            'conversacion_id'=>$conversation->id,
            'usuario_id'=>$user->id,
            'client_request_id'=>$requestId,
            'mensaje'=>'Mensaje escrito sin Internet.',
        ]);
    }

    public function test_same_request_id_cannot_be_reused_in_another_conversation(): void
    {
        $client=Cliente::create([
            'nombre'=>'Cliente Offline Dos',
            'telefono'=>'73992222',
            'estado'=>'informacion_recibida',
        ]);
        $user=Usuario::create([
            'cliente_id'=>$client->id,
            'nombre'=>'Cliente Offline Dos',
            'usuario'=>'cliente_offline_conflicto',
            'documento'=>'88776656',
            'telefono'=>'73992222',
            'password'=>'Prueba1234',
            'rol'=>'cliente',
            'estado'=>'activo',
        ]);
        $firstConversation=Conversacion::create([
            'cliente_id'=>$client->id,'asunto'=>'Canal 1','estado'=>'abierta','contexto'=>'viti','canal_principal'=>true,
        ]);
        $secondConversation=Conversacion::create([
            'cliente_id'=>$client->id,'asunto'=>'Canal 2','estado'=>'abierta','contexto'=>'viti','canal_principal'=>false,
        ]);
        $requestId=(string)Str::uuid();
        $payload=['mensaje'=>'No debe migrar de conversación.','client_request_id'=>$requestId];

        $this->actingAs($user)->postJson("/api/v1/mi/buzon/{$firstConversation->id}/mensajes",$payload)->assertSuccessful();
        $this->actingAs($user)
            ->postJson("/api/v1/mi/buzon/{$secondConversation->id}/mensajes",$payload)
            ->assertStatus(409)
            ->assertJsonPath('message','El identificador del mensaje ya fue utilizado.');

        $this->assertSame(1,Mensaje::query()->where('client_request_id',$requestId)->count());
    }
}
