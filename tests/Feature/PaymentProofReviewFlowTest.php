<?php

namespace Tests\Feature;

use App\Models\{Cliente,Empresa,Proyecto,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentProofReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_proof_does_not_reduce_balance_until_admin_confirms_it(): void
    {
        Storage::fake('private_uploads');
        [$clientUser,$admin,$company,$project]=$this->scenario();

        $response=$this->actingAs($clientUser)
            ->withHeader('X-VITI-Empresa',(string)$company->id)
            ->post('/api/v1/mi/pagos/proyectos/'.$project->id.'/comprobante',[
                'monto'=>975,
                'metodo'=>'qr',
                'fecha_pago'=>'2026-08-10',
                'referencia'=>'QR-TEST-001',
                'comprobante'=>UploadedFile::fake()->image('comprobante.png',800,800),
            ]);

        $response->assertCreated()->assertJsonPath('data.estado_revision','pendiente_revision');
        $paymentId=(int)$response->json('data.id');

        $this->assertDatabaseHas('proyecto_pagos',[
            'id'=>$paymentId,
            'proyecto_id'=>$project->id,
            'monto'=>975,
            'estado_revision'=>'pendiente_revision',
            'origen'=>'cliente',
        ]);
        $this->assertDatabaseHas('proyectos',['id'=>$project->id,'estado_pago'=>'pendiente_anticipo']);

        $this->actingAs($clientUser)
            ->withHeader('X-VITI-Empresa',(string)$company->id)
            ->getJson('/api/v1/mi/pagos')
            ->assertOk()
            ->assertJsonPath('data.proyectos.0.pagado',0)
            ->assertJsonPath('data.proyectos.0.pendiente',1950)
            ->assertJsonPath('data.proyectos.0.siguiente_pago.monto',975)
            ->assertJsonPath('data.proyectos.0.siguiente_pago.puede_enviar',false);

        $this->actingAs($admin)
            ->postJson('/api/v1/pagos/proyecto-pagos/'.$paymentId.'/confirmar')
            ->assertOk();

        $this->assertDatabaseHas('proyecto_pagos',['id'=>$paymentId,'estado_revision'=>'confirmado']);
        $this->assertDatabaseHas('proyectos',['id'=>$project->id,'estado_pago'=>'pendiente_saldo']);

        $this->actingAs($clientUser)
            ->withHeader('X-VITI-Empresa',(string)$company->id)
            ->getJson('/api/v1/mi/pagos')
            ->assertOk()
            ->assertJsonPath('data.proyectos.0.pagado',975)
            ->assertJsonPath('data.proyectos.0.pendiente',975)
            ->assertJsonPath('data.proyectos.0.siguiente_pago.tipo','saldo_final')
            ->assertJsonPath('data.proyectos.0.siguiente_pago.monto',975)
            ->assertJsonPath('data.proyectos.0.siguiente_pago.puede_enviar',true);
    }

    public function test_rejected_proof_keeps_balance_and_allows_a_new_submission(): void
    {
        Storage::fake('private_uploads');
        [$clientUser,$admin,$company,$project]=$this->scenario();

        $response=$this->actingAs($clientUser)
            ->withHeader('X-VITI-Empresa',(string)$company->id)
            ->post('/api/v1/mi/pagos/proyectos/'.$project->id.'/comprobante',[
                'monto'=>975,
                'metodo'=>'transferencia',
                'fecha_pago'=>'2026-08-10',
                'comprobante'=>UploadedFile::fake()->create('transferencia.pdf',120,'application/pdf'),
            ]);
        $paymentId=(int)$response->json('data.id');

        $this->actingAs($admin)
            ->postJson('/api/v1/pagos/proyecto-pagos/'.$paymentId.'/rechazar',['motivo'=>'El comprobante no permite verificar la operación.'])
            ->assertOk();

        $this->assertDatabaseHas('proyecto_pagos',[
            'id'=>$paymentId,
            'estado_revision'=>'rechazado',
            'motivo_revision'=>'El comprobante no permite verificar la operación.',
        ]);
        $this->assertDatabaseHas('proyectos',['id'=>$project->id,'estado_pago'=>'pendiente_anticipo']);

        $this->actingAs($clientUser)
            ->withHeader('X-VITI-Empresa',(string)$company->id)
            ->getJson('/api/v1/mi/pagos')
            ->assertOk()
            ->assertJsonPath('data.proyectos.0.pagado',0)
            ->assertJsonPath('data.proyectos.0.siguiente_pago.puede_enviar',true);
    }

    private function scenario(): array
    {
        $client=Cliente::create(['nombre'=>'Laura Cordero','telefono'=>'73997967','estado'=>'informacion_recibida']);
        $clientUser=Usuario::create([
            'cliente_id'=>$client->id,
            'nombre'=>'Laura',
            'apellido'=>'Cordero',
            'usuario'=>'laura_pago_test',
            'documento'=>'13600001',
            'telefono'=>'73997967',
            'password'=>'Prueba1234',
            'rol'=>'cliente',
            'estado'=>'activo',
        ]);
        $admin=Usuario::create([
            'nombre'=>'Admin VITI',
            'usuario'=>'admin_pago_test',
            'documento'=>'13600002',
            'telefono'=>'70000002',
            'password'=>'Prueba1234',
            'rol'=>'superadmin',
            'estado'=>'activo',
        ]);
        $company=Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-PAGO-TEST',
            'nombre_comercial'=>'Soporte Vital PC',
            'estado'=>'activo',
        ]);
        $clientUser->negocios()->attach($company->id,[
            'rol_negocio'=>'propietario',
            'activo'=>true,
            'permisos'=>null,
        ]);
        $project=Proyecto::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'codigo'=>'PRO-PAGO-TEST',
            'nombre'=>'Sistema para Soporte Vital PC',
            'fase'=>'desarrollo',
            'estado'=>'activo',
            'progreso'=>50,
            'fecha_inicio'=>'2026-08-10',
            'precio_acordado'=>1950,
            'anticipo_monto'=>975,
            'saldo_monto'=>975,
            'estado_pago'=>'pendiente_anticipo',
        ]);

        return [$clientUser,$admin,$company,$project];
    }
}
