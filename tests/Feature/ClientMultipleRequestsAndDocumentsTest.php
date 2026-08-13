<?php

namespace Tests\Feature;

use App\Models\{Cliente,Conversacion,Cuestionario,Empresa,SolicitudSistema,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientMultipleRequestsAndDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_request_does_not_reopen_a_converted_request_and_history_keeps_both(): void
    {
        [$client,$user]=$this->clientScenario();
        $questionnaire=Cuestionario::create(['nombre'=>'Formulario VITI','version'=>'1.0','activo'=>true]);
        $company=Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-MULTI-REQ',
            'nombre_comercial'=>'Empresa Laura',
            'estado'=>'activo',
        ]);

        $old=SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'codigo'=>'SOL-OLD-001',
            'public_token'=>'old-token-for-test',
            'publico_habilitado'=>true,
            'titulo'=>'Sistema anterior',
            'estado'=>'convertida',
            'prioridad'=>'normal',
        ]);

        $response=$this->actingAs($user)->postJson('/api/v1/mi/solicitud');
        $response->assertCreated()
            ->assertJsonPath('data.estado','borrador')
            ->assertJsonPath('data.empresa.id',$company->id);

        $newId=(int)$response->json('data.id');
        $this->assertNotSame((int)$old->id,$newId);
        $this->assertDatabaseHas('solicitudes_sistema',['id'=>$old->id,'estado'=>'convertida']);
        $this->assertDatabaseHas('solicitudes_sistema',['id'=>$newId,'cliente_id'=>$client->id,'empresa_id'=>$company->id,'estado'=>'borrador']);

        $history=$this->actingAs($user)->getJson('/api/v1/mi/solicitudes')->assertOk();
        $history->assertJsonCount(2,'data');
        $this->assertSame($newId,(int)$history->json('data.0.id'));
        $this->assertSame((int)$old->id,(int)$history->json('data.1.id'));
    }

    public function test_client_can_send_pdf_but_executable_files_are_rejected(): void
    {
        Storage::fake('private_uploads');
        [$client,$user]=$this->clientScenario();
        $conversation=Conversacion::create([
            'cliente_id'=>$client->id,
            'asunto'=>'Atención VITI',
            'estado'=>'abierta',
            'contexto'=>'viti',
            'canal_principal'=>true,
        ]);

        $this->actingAs($user)
            ->post('/api/v1/mi/buzon/'.$conversation->id.'/documentos',[
                'mensaje'=>'Adjunto propuesta',
                'archivo'=>UploadedFile::fake()->create('propuesta.pdf',120,'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.tipo','archivo')
            ->assertJsonPath('data.archivo_nombre','propuesta.pdf');

        $this->assertDatabaseHas('mensajes',[
            'conversacion_id'=>$conversation->id,
            'usuario_id'=>$user->id,
            'tipo'=>'archivo',
            'archivo_nombre'=>'propuesta.pdf',
        ]);

        $this->actingAs($user)
            ->post('/api/v1/mi/buzon/'.$conversation->id.'/documentos',[
                'archivo'=>UploadedFile::fake()->create('programa.exe',20,'application/octet-stream'),
            ])
            ->assertStatus(422);
    }

    private function clientScenario(): array
    {
        $client=Cliente::create([
            'nombre'=>'Laura Cordero',
            'telefono'=>'73997967',
            'estado'=>'informacion_recibida',
        ]);
        $user=Usuario::create([
            'cliente_id'=>$client->id,
            'nombre'=>'Laura',
            'apellido'=>'Cordero',
            'usuario'=>'laura_multi_test',
            'documento'=>'4198530',
            'telefono'=>'73997967',
            'password'=>'Prueba1234',
            'rol'=>'cliente',
            'estado'=>'activo',
        ]);
        return [$client,$user];
    }
}
