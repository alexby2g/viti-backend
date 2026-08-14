<?php

namespace Tests\Feature;

use App\Models\{Aplicacion,Cliente,Cuestionario,Empresa,PlanViti,Proyecto,SolicitudSistema,Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoreWorkflowIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_must_be_approved_before_becoming_project(): void
    {
        [$admin,$client,$company] = $this->fixture('REQUEST-BLOCK');
        $request = $this->systemRequest($company,$client,'en_revision');

        $this->actingAs($admin)
            ->postJson('/api/v1/proyectos', $this->projectPayload($company,$client,$request))
            ->assertStatus(422)
            ->assertJsonPath('message','La solicitud debe estar aprobada antes de convertirse en proyecto.');

        $this->assertDatabaseMissing('proyectos',['solicitud_id'=>$request->id]);
        $this->assertDatabaseHas('solicitudes_sistema',['id'=>$request->id,'estado'=>'en_revision']);
    }

    public function test_approved_request_becomes_project_and_is_marked_converted(): void
    {
        [$admin,$client,$company] = $this->fixture('REQUEST-OK');
        $request = $this->systemRequest($company,$client,'aprobada');

        $this->actingAs($admin)
            ->postJson('/api/v1/proyectos', $this->projectPayload($company,$client,$request))
            ->assertCreated()
            ->assertJsonPath('data.empresa_id',$company->id);

        $this->assertDatabaseHas('proyectos',['solicitud_id'=>$request->id,'empresa_id'=>$company->id]);
        $this->assertDatabaseHas('solicitudes_sistema',['id'=>$request->id,'estado'=>'convertida']);
    }

    public function test_application_cannot_link_project_from_another_company(): void
    {
        [$admin,$clientA,$companyA] = $this->fixture('APP-A');
        [,,$companyB] = $this->fixture('APP-B');
        $project = Proyecto::create([
            'empresa_id'=>$companyA->id,
            'cliente_id'=>$clientA->id,
            'codigo'=>'PRO-CROSS-TENANT',
            'nombre'=>'Proyecto empresa A',
            'fase'=>'levantamiento',
            'estado'=>'activo',
            'progreso'=>0,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/aplicaciones',[
                'empresa_id'=>$companyB->id,
                'proyecto_id'=>$project->id,
                'nombre'=>'App cruzada',
                'tipo'=>'web',
                'entorno'=>'desarrollo',
                'estado'=>'en_pruebas',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message','El proyecto seleccionado pertenece a otra empresa.');

        $this->assertDatabaseMissing('aplicaciones',['nombre'=>'App cruzada']);
    }

    public function test_plan_application_limit_is_enforced_by_backend(): void
    {
        [$admin,$client,$company] = $this->fixture('APP-LIMIT');
        $plan = PlanViti::create([
            'codigo'=>'test-limit-'.strtolower((string)$company->id),
            'nombre'=>'Plan límite prueba',
            'max_aplicaciones'=>1,
            'max_usuarios'=>3,
            'activo'=>true,
        ]);
        $company->update(['plan_viti_id'=>$plan->id]);

        Aplicacion::create([
            'empresa_id'=>$company->id,
            'nombre'=>'App existente',
            'slug'=>'app-existente-'.$company->id,
            'tipo'=>'web',
            'entorno'=>'produccion',
            'estado'=>'activo',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/aplicaciones',[
                'empresa_id'=>$company->id,
                'nombre'=>'App excedente',
                'tipo'=>'web',
                'entorno'=>'desarrollo',
                'estado'=>'en_pruebas',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message','Este negocio alcanzó el límite de aplicaciones de su plan VITI.');

        $this->assertDatabaseMissing('aplicaciones',['nombre'=>'App excedente']);
    }

    public function test_general_application_update_cannot_move_app_to_another_company(): void
    {
        [$admin,,$companyA] = $this->fixture('APP-MOVE-A');
        [,,$companyB] = $this->fixture('APP-MOVE-B');
        $app = Aplicacion::create([
            'empresa_id'=>$companyA->id,
            'nombre'=>'App original',
            'slug'=>'app-original-'.$companyA->id,
            'tipo'=>'web',
            'entorno'=>'beta',
            'estado'=>'en_pruebas',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/aplicaciones/{$app->id}",[
                'empresa_id'=>$companyB->id,
                'nombre'=>'App renombrada',
                'tipo'=>'web',
            ])
            ->assertOk()
            ->assertJsonPath('data.nombre','App renombrada')
            ->assertJsonPath('data.empresa_id',$companyA->id);

        $this->assertDatabaseHas('aplicaciones',[
            'id'=>$app->id,
            'empresa_id'=>$companyA->id,
            'nombre'=>'App renombrada',
        ]);
    }

    private function fixture(string $suffix): array
    {
        $admin = Usuario::create([
            'nombre'=>'Admin Core',
            'usuario'=>'admin_'.strtolower(str_replace('-','_',$suffix)),
            'documento'=>'9'.str_pad((string)abs(crc32('ci-'.$suffix)),9,'0',STR_PAD_LEFT),
            'telefono'=>'7'.substr(str_pad((string)abs(crc32('tel-'.$suffix)),8,'0',STR_PAD_LEFT),0,8),
            'password'=>'Prueba1234',
            'rol'=>'administrador',
            'estado'=>'activo',
        ]);
        $client = Cliente::create([
            'nombre'=>'Cliente '.$suffix,
            'telefono'=>'6'.substr(str_pad((string)abs(crc32('cli-'.$suffix)),8,'0',STR_PAD_LEFT),0,8),
            'estado'=>'informacion_recibida',
        ]);
        $company = Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-'.$suffix,
            'nombre_comercial'=>'Empresa '.$suffix,
            'estado'=>'activo',
        ]);
        return [$admin,$client,$company];
    }

    private function systemRequest(Empresa $company, Cliente $client, string $state): SolicitudSistema
    {
        $questionnaire = Cuestionario::query()->where('activo',true)->first();
        if (!$questionnaire) {
            $this->seed(CuestionarioSeeder::class);
            $questionnaire = Cuestionario::query()->where('activo',true)->firstOrFail();
        }

        return SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'codigo'=>'SOL-'.$company->id.'-'.$state,
            'public_token'=>str_repeat((string)(($company->id % 9)+1),48),
            'publico_habilitado'=>true,
            'titulo'=>'Solicitud '.$company->nombre_comercial,
            'estado'=>$state,
            'prioridad'=>'normal',
            'aprobado_at'=>$state==='aprobada' ? now() : null,
        ]);
    }

    private function projectPayload(Empresa $company, Cliente $client, SolicitudSistema $request): array
    {
        return [
            'solicitud_id'=>$request->id,
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'nombre'=>'Proyecto '.$company->nombre_comercial,
            'fase'=>'levantamiento',
            'estado'=>'activo',
            'progreso'=>0,
        ];
    }
}
