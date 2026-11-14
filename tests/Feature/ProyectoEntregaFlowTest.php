<?php

namespace Tests\Feature;

use App\Models\{Archivo,Cliente,Empresa,Proyecto,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProyectoEntregaFlowTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(string $code): array
    {
        Storage::fake('private_uploads');
        $cliente = Cliente::create([
            'nombre' => 'Cliente '.$code,
            'telefono' => '798710'.$code,
            'estado' => 'informacion_recibida',
        ]);
        $company = Empresa::create([
            'cliente_id' => $cliente->id,
            'codigo' => 'EMP-DEL-'.$code,
            'nombre_comercial' => 'Comercio '.$code,
            'estado' => 'activo',
        ]);
        $clientUser = Usuario::create([
            'cliente_id' => $cliente->id, 'nombre' => 'Cliente',
            'usuario' => 'entrega_client_'.$code, 'documento' => '88770'.$code,
            'telefono' => '73870'.$code, 'password' => 'Prueba123456',
            'rol' => 'cliente', 'estado' => 'activo',
        ]);
        $clientUser->negocios()->syncWithoutDetaching([$company->id => [
            'rol_negocio' => 'propietario', 'activo' => true,
        ]]);
        $admin = Usuario::create([
            'nombre' => 'Administrador', 'usuario' => 'entrega_admin_'.$code,
            'documento' => '99880'.$code, 'telefono' => '72880'.$code,
            'password' => 'Prueba123456', 'rol' => 'superadmin', 'estado' => 'activo',
        ]);
        $project = Proyecto::create([
            'empresa_id' => $company->id, 'cliente_id' => $cliente->id,
            'codigo' => 'PRO-DEL-'.$code, 'nombre' => 'Proyecto '.$code,
            'fase' => 'desarrollo', 'estado' => 'activo', 'progreso' => 70,
            'precio_acordado' => 1200, 'anticipo_monto' => 600, 'saldo_monto' => 600,
            'estado_pago' => 'pendiente_saldo',
        ]);

        return compact('company', 'clientUser', 'admin', 'project');
    }

    private function file(Proyecto $project, string $name = 'entregable.pdf', string $category = 'entrega'): Archivo
    {
        $path = 'archivos/test/'.uniqid().'.pdf';
        Storage::disk('private_uploads')->put($path, 'contenido de entrega');
        return Archivo::create([
            'adjuntable_type' => Proyecto::class, 'adjuntable_id' => $project->id,
            'categoria' => $category, 'nombre_original' => $name, 'ruta' => $path,
            'mime' => 'application/pdf', 'tamano' => 19,
        ]);
    }

    public function test_publishing_is_explicit_requires_paid_agreement_and_only_selected_files_appear_to_client(): void
    {
        ['company' => $company, 'clientUser' => $client,
         'admin' => $admin, 'project' => $project] = $this->scenario('01');
        $selected = $this->file($project, 'manual.pdf');
        $private = $this->file($project, 'codigo-fuente.pdf');

        $this->actingAs($admin)->putJson("/api/v1/proyectos/{$project->id}/entrega", [
            'titulo' => 'Entrega final',
            'instrucciones' => 'Descarga el manual para comenzar.',
            'archivo_ids' => [$selected->id],
        ])->assertOk()->assertJsonPath('data.estado', 'borrador');

        $this->actingAs($client)->withHeader('X-VITI-Empresa', (string)$company->id)
            ->getJson('/api/v1/mi/proyecto/entrega')
            ->assertOk()->assertJsonPath('data', null);

        $this->get("/api/v1/mi/archivos/{$selected->id}/descargar")->assertForbidden();

        $this->actingAs($admin)
            ->postJson("/api/v1/proyectos/{$project->id}/entrega/publicar")
            ->assertUnprocessable();

        $project->update(['estado_pago' => 'pagado']);
        $this->postJson("/api/v1/proyectos/{$project->id}/entrega/publicar")
            ->assertOk()->assertJsonPath('data.estado', 'publicada');

        $this->actingAs($client)->withHeader('X-VITI-Empresa', (string)$company->id)
            ->getJson('/api/v1/mi/proyecto/entrega')->assertOk()
            ->assertJsonPath('data.titulo', 'Entrega final')
            ->assertJsonCount(1, 'data.archivos')
            ->assertJsonPath('data.archivos.0.id', $selected->id)
            ->assertJsonMissingPath('data.archivo_ids');

        $this->get("/api/v1/mi/archivos/{$selected->id}/descargar")->assertOk();
        $this->get("/api/v1/mi/archivos/{$private->id}/descargar")->assertForbidden();

        $this->assertSame(70, $project->fresh()->progreso);
        $this->assertSame('desarrollo', $project->fresh()->fase);
        $this->assertDatabaseHas('auditoria', ['accion'=>'proyecto_entrega_publicada']);
    }

    public function test_delivery_cannot_include_files_of_other_projects_or_other_categories(): void
    {
        ['admin' => $admin, 'project' => $project] = $this->scenario('02');
        ['project' => $other] = $this->scenario('03');
        $foreign = $this->file($other);
        $internal = $this->file($project, 'private.pdf', 'general');

        $this->actingAs($admin)->putJson("/api/v1/proyectos/{$project->id}/entrega", [
            'titulo' => 'Paquete',
            'archivo_ids' => [$foreign->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('archivo_ids');

        $this->putJson("/api/v1/proyectos/{$project->id}/entrega", [
            'titulo' => 'Paquete', 'archivo_ids' => [$internal->id],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('proyecto_entregas', 0);
    }

    public function test_withdraw_hides_files_and_prevents_draft_edits_to_published_package(): void
    {
        ['company' => $company, 'clientUser' => $client,
         'admin' => $admin, 'project' => $project] = $this->scenario('04');
        $file = $this->file($project);
        $project->update(['estado_pago' => 'pagado']);

        $this->actingAs($admin)->putJson("/api/v1/proyectos/{$project->id}/entrega", [
            'titulo' => 'Manual de uso', 'archivo_ids' => [$file->id],
        ])->assertOk();
        $this->postJson("/api/v1/proyectos/{$project->id}/entrega/publicar")->assertOk();

        $this->putJson("/api/v1/proyectos/{$project->id}/entrega", [
            'titulo' => 'Cambiado', 'archivo_ids' => [],
        ])->assertUnprocessable();

        $this->postJson("/api/v1/proyectos/{$project->id}/entrega/retirar")
            ->assertOk()->assertJsonPath('data.estado', 'borrador');

        $this->actingAs($client)->withHeader('X-VITI-Empresa',(string)$company->id)
            ->getJson('/api/v1/mi/proyecto/entrega')->assertOk()->assertJsonPath('data', null);

        $this->get("/api/v1/mi/archivos/{$file->id}/descargar")->assertForbidden();
        $this->assertDatabaseHas('archivos', ['id' => $file->id]);
        $this->assertDatabaseHas('proyecto_entregas', ['proyecto_id' => $project->id,'estado'=>'borrador']);
    }

    public function test_client_may_not_view_others_package_even_with_a_guessed_file_id(): void
    {
        $tenantA = $this->scenario('05');
        $tenantB = $this->scenario('06');
        $projectB = $tenantB['project'];
        $fileB = $this->file($projectB);
        $projectB->update(['estado_pago' => 'pagado']);

        $this->actingAs($tenantB['admin'])
            ->putJson("/api/v1/proyectos/{$projectB->id}/entrega", [
                'titulo' => 'Entrega de otro negocio', 'archivo_ids' => [$fileB->id],
            ])->assertOk();
        $this->postJson("/api/v1/proyectos/{$projectB->id}/entrega/publicar")->assertOk();

        $this->actingAs($tenantA['clientUser'])
            ->withHeader('X-VITI-Empresa', (string)$tenantA['company']->id)
            ->getJson('/api/v1/mi/proyecto/entrega')->assertOk()->assertJsonPath('data', null);

        $this->get("/api/v1/mi/archivos/{$fileB->id}/descargar")->assertNotFound();
    }

    public function test_clients_and_guests_cannot_modify_delivery(): void
    {
        ['company'=>$company, 'clientUser'=>$client, 'project'=>$project] = $this->scenario('07');
        $url = "/api/v1/proyectos/{$project->id}/entrega";
        $this->putJson($url, ['titulo'=>'No permitido', 'archivo_ids'=>[]])->assertUnauthorized();
        $this->actingAs($client)->withHeader('X-VITI-Empresa',(string)$company->id)
            ->putJson($url, ['titulo'=>'No permitido', 'archivo_ids'=>[]])->assertForbidden();
        $this->postJson($url.'/publicar')->assertForbidden();
        $this->assertDatabaseCount('proyecto_entregas',0);
    }
}
