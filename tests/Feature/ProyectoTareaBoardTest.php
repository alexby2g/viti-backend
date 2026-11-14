<?php

namespace Tests\Feature;

use App\Models\{Cliente, Empresa, Proyecto, ProyectoMiembro, Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProyectoTareaBoardTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $rol, string $suffix): Usuario
    {
        return Usuario::create([
            'nombre' => 'Usuario '.$suffix,
            'usuario' => 'task_user_'.$suffix,
            'documento' => '988980'.$suffix,
            'telefono' => '798980'.$suffix,
            'password' => 'Prueba123456',
            'rol' => $rol,
            'estado' => 'activo',
        ]);
    }

    private function project(string $suffix): Proyecto
    {
        $client = Cliente::create([
            'nombre' => 'Cliente '.$suffix,
            'telefono' => '7891234'.$suffix,
            'estado' => 'informacion_recibida',
        ]);
        $company = Empresa::create([
            'cliente_id' => $client->id,
            'codigo' => 'EMP-TASK-'.$suffix,
            'nombre_comercial' => 'Empresa '.$suffix,
            'actividad' => 'Servicios',
            'estado' => 'activo',
        ]);

        return Proyecto::create([
            'empresa_id' => $company->id,
            'cliente_id' => $client->id,
            'codigo' => 'PRO-TASK-'.$suffix,
            'nombre' => 'Trabajo '.$suffix,
            'fase' => 'desarrollo',
            'estado' => 'activo',
            'progreso' => 35,
        ]);
    }

    private function payload(array $changes = []): array
    {
        return array_merge([
            'titulo' => 'Crear API de productos',
            'descripcion' => 'Implementar los controladores.',
            'criterios_aceptacion' => 'Pasa las pruebas de autenticación y CRUD.',
            'area' => 'backend',
            'prioridad' => 'alta',
            'estado' => 'pendiente',
        ], $changes);
    }

    public function test_admin_can_create_list_update_tasks_without_changing_project_phase_or_progress(): void
    {
        $admin = $this->actor('administrador', '01');
        $member = $this->actor('administrador', '02');
        $project = $this->project('01');

        ProyectoMiembro::create([
            'proyecto_id' => $project->id,
            'usuario_id' => $member->id,
            'rol' => 'desarrollador',
            'activo' => true,
        ]);

        $id = $this->actingAs($admin)
            ->postJson("/api/v1/proyectos/{$project->id}/tareas", $this->payload([
                'responsable_id' => $member->id,
                'fecha_limite' => '2026-12-01',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.responsable_id', $member->id)
            ->json('data.id');

        $this->getJson("/api/v1/proyectos/{$project->id}/tareas")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.pendientes', 1);

        $this->patchJson("/api/v1/proyectos/{$project->id}/tareas/{$id}", [
            'estado' => 'en_desarrollo',
        ])->assertOk()->assertJsonPath('data.estado', 'en_desarrollo');

        $this->patchJson("/api/v1/proyectos/{$project->id}/tareas/{$id}", [
            'estado' => 'en_revision',
        ])->assertOk()->assertJsonPath('data.estado', 'en_revision');

        $this->patchJson("/api/v1/proyectos/{$project->id}/tareas/{$id}", [
            'estado' => 'completada',
        ])->assertOk()->assertJsonPath('data.estado', 'completada');

        $this->assertNotNull($project->tareas()->findOrFail($id)->completada_at);
        $this->getJson("/api/v1/proyectos/{$project->id}/tareas")
            ->assertOk()->assertJsonPath('summary.completadas', 1);

        $this->patchJson("/api/v1/proyectos/{$project->id}/tareas/{$id}", [
            'estado' => 'bloqueada',
        ])->assertOk()->assertJsonPath('data.completada_at', null);

        $project->refresh();
        $this->assertSame(35, $project->progreso);
        $this->assertSame('desarrollo', $project->fase);
        $this->assertSame('activo', $project->estado);
        $this->assertDatabaseHas('proyecto_tareas', ['id' => $id, 'proyecto_id' => $project->id]);
        $this->assertDatabaseHas('auditoria', ['accion' => 'proyecto_tarea_creada']);
    }

    public function test_completion_requires_acceptance_criteria(): void
    {
        $admin = $this->actor('administrador', '03');
        $project = $this->project('02');

        $this->actingAs($admin)
            ->postJson("/api/v1/proyectos/{$project->id}/tareas", $this->payload([
                'estado' => 'completada',
                'criterios_aceptacion' => null,
            ]))->assertUnprocessable();

        $id = $this->postJson("/api/v1/proyectos/{$project->id}/tareas", $this->payload([
            'criterios_aceptacion' => null,
        ]))->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/proyectos/{$project->id}/tareas/{$id}", [
            'estado' => 'completada',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['criterios_aceptacion']);

        $this->assertDatabaseHas('proyecto_tareas', ['id' => $id, 'estado' => 'pendiente']);
    }

    public function test_assignee_must_be_active_project_member_or_responsible(): void
    {
        $admin = $this->actor('administrador', '04');
        $outsider = $this->actor('administrador', '05');
        $project = $this->project('03');

        $this->actingAs($admin)->postJson("/api/v1/proyectos/{$project->id}/tareas", $this->payload([
            'responsable_id' => $outsider->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['responsable_id']);

        ProyectoMiembro::create([
            'proyecto_id' => $project->id,
            'usuario_id' => $outsider->id,
            'rol' => 'qa',
            'activo' => false,
        ]);

        $this->postJson("/api/v1/proyectos/{$project->id}/tareas", $this->payload([
            'responsable_id' => $outsider->id,
        ]))->assertUnprocessable();

        $this->assertDatabaseCount('proyecto_tareas', 0);
    }

    public function test_tasks_cannot_be_accessed_or_modified_using_another_project_id(): void
    {
        $admin = $this->actor('administrador', '06');
        $projectA = $this->project('04');
        $projectB = $this->project('05');

        $id = $this->actingAs($admin)
            ->postJson("/api/v1/proyectos/{$projectA->id}/tareas", $this->payload())
            ->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/proyectos/{$projectB->id}/tareas/{$id}", [
            'estado' => 'completada',
        ])->assertNotFound();

        $this->getJson("/api/v1/proyectos/{$projectB->id}/tareas")
            ->assertOk()->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('proyecto_tareas', ['id' => $id, 'estado' => 'pendiente']);
    }

    public function test_guest_and_client_cannot_access_admin_task_board(): void
    {
        $project = $this->project('06');

        $this->getJson("/api/v1/proyectos/{$project->id}/tareas")->assertUnauthorized();
        $this->postJson("/api/v1/proyectos/{$project->id}/tareas", $this->payload())
            ->assertUnauthorized();

        $client = $this->actor('cliente', '07');
        $this->actingAs($client)
            ->getJson("/api/v1/proyectos/{$project->id}/tareas")->assertForbidden();
        $this->postJson("/api/v1/proyectos/{$project->id}/tareas", $this->payload())
            ->assertForbidden();
    }

    public function test_finalized_or_cancelled_projects_are_read_only(): void
    {
        $admin = $this->actor('administrador', '08');
        $project = $this->project('07');
        $id = $this->actingAs($admin)
            ->postJson("/api/v1/proyectos/{$project->id}/tareas", $this->payload())
            ->assertCreated()->json('data.id');

        $project->update(['estado' => 'cancelado']);

        $this->postJson("/api/v1/proyectos/{$project->id}/tareas", $this->payload())
            ->assertUnprocessable();

        $this->patchJson("/api/v1/proyectos/{$project->id}/tareas/{$id}", [
            'estado' => 'completada',
        ])->assertUnprocessable();

        $this->getJson("/api/v1/proyectos/{$project->id}/tareas")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->assertDatabaseCount('proyecto_tareas', 1);
    }

    public function test_invalid_data_is_rejected_without_creating_tasks(): void
    {
        $admin = $this->actor('administrador', '09');
        $project = $this->project('08');

        $this->actingAs($admin)
            ->postJson("/api/v1/proyectos/{$project->id}/tareas", $this->payload([
                'titulo' => 'a',
                'estado' => 'eliminada',
                'prioridad' => 'maxima',
                'fecha_limite' => 'manana',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['titulo','estado','prioridad','fecha_limite']);

        $this->assertDatabaseCount('proyecto_tareas', 0);
    }
}
