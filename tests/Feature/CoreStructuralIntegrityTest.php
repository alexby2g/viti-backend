<?php

namespace Tests\Feature;

use App\Models\{Cliente,Cuestionario,Empresa,Proyecto,SolicitudSistema,Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoreStructuralIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_links_cannot_be_moved_after_creation(): void
    {
        [$admin,$clientA,$companyA]=$this->fixture('LINK-A');
        [,$clientB,$companyB]=$this->fixture('LINK-B');
        $project=Proyecto::create([
            'empresa_id'=>$companyA->id,
            'cliente_id'=>$clientA->id,
            'codigo'=>'PRO-LINK-LOCK',
            'nombre'=>'Proyecto fijo',
            'fase'=>'levantamiento',
            'estado'=>'activo',
            'progreso'=>0,
        ]);

        $this->actingAs($admin)->putJson('/api/v1/proyectos/'.$project->id,[
            'empresa_id'=>$companyB->id,
            'cliente_id'=>$clientB->id,
            'nombre'=>'Intento de mover proyecto',
            'fase'=>'levantamiento',
            'estado'=>'activo',
            'progreso'=>0,
        ])->assertStatus(422)->assertJsonValidationErrors('empresa_id');

        $this->assertDatabaseHas('proyectos',[
            'id'=>$project->id,
            'empresa_id'=>$companyA->id,
            'cliente_id'=>$clientA->id,
        ]);
    }

    public function test_paused_project_cannot_advance_phase_through_progress_endpoint(): void
    {
        [$admin,$client,$company]=$this->fixture('PAUSED');
        $project=Proyecto::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'codigo'=>'PRO-PAUSED',
            'nombre'=>'Proyecto pausado',
            'fase'=>'desarrollo',
            'estado'=>'pausado',
            'progreso'=>45,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/proyectos/'.$project->id.'/avances',[
            'fase'=>'beta',
            'area'=>'qa',
            'titulo'=>'Intento de avance pausado',
            'progreso'=>60,
        ])->assertStatus(422)->assertJsonValidationErrors('fase');

        $this->assertDatabaseHas('proyectos',[
            'id'=>$project->id,
            'fase'=>'desarrollo',
            'estado'=>'pausado',
            'progreso'=>45,
        ]);
    }

    public function test_final_progress_closes_phase_and_state_atomically(): void
    {
        [$admin,$client,$company]=$this->fixture('FINISH');
        $project=Proyecto::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'codigo'=>'PRO-FINISH',
            'nombre'=>'Proyecto a finalizar',
            'fase'=>'implementacion',
            'estado'=>'activo',
            'progreso'=>95,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/proyectos/'.$project->id.'/avances',[
            'fase'=>'finalizado',
            'area'=>'qa',
            'titulo'=>'Entrega final',
        ])->assertCreated()
          ->assertJsonPath('workflow.fase.actual','finalizado')
          ->assertJsonPath('workflow.estado.actual','finalizado');

        $this->assertDatabaseHas('proyectos',[
            'id'=>$project->id,
            'fase'=>'finalizado',
            'estado'=>'finalizado',
            'progreso'=>100,
        ]);
    }

    public function test_generic_update_cannot_mark_project_final_without_consistent_phase(): void
    {
        [$admin,$client,$company]=$this->fixture('BAD-FINAL');
        $project=Proyecto::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'codigo'=>'PRO-BAD-FINAL',
            'nombre'=>'Proyecto incompleto',
            'fase'=>'implementacion',
            'estado'=>'activo',
            'progreso'=>90,
        ]);

        $this->actingAs($admin)->putJson('/api/v1/proyectos/'.$project->id,[
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'nombre'=>'Proyecto incompleto',
            'fase'=>'implementacion',
            'estado'=>'finalizado',
            'progreso'=>100,
        ])->assertStatus(422)->assertJsonValidationErrors('estado');
    }

    public function test_approved_request_cannot_be_marked_converted_without_project(): void
    {
        [$admin,$client,$company]=$this->fixture('CONVERT');
        $questionnaire=$this->questionnaire();
        $request=SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'codigo'=>'SOL-CONVERT-LOCK',
            'public_token'=>Str::random(48),
            'publico_habilitado'=>true,
            'titulo'=>'Solicitud aprobada sin proyecto',
            'estado'=>'aprobada',
            'prioridad'=>'normal',
        ]);

        $this->actingAs($admin)->putJson('/api/v1/solicitudes/'.$request->id,[
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'titulo'=>$request->titulo,
            'estado'=>'convertida',
            'prioridad'=>'normal',
        ])->assertStatus(422)->assertJsonValidationErrors('estado');

        $this->assertDatabaseHas('solicitudes_sistema',['id'=>$request->id,'estado'=>'aprobada']);
    }

    public function test_linked_project_cannot_be_deleted_and_leave_converted_request_orphaned(): void
    {
        [$admin,$client,$company]=$this->fixture('DELETE');
        $questionnaire=$this->questionnaire();
        $request=SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'codigo'=>'SOL-LINKED-DELETE',
            'public_token'=>Str::random(48),
            'publico_habilitado'=>true,
            'titulo'=>'Solicitud convertida',
            'estado'=>'convertida',
            'prioridad'=>'normal',
        ]);
        $project=Proyecto::create([
            'solicitud_id'=>$request->id,
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'codigo'=>'PRO-LINKED-DELETE',
            'nombre'=>'Proyecto trazable',
            'fase'=>'levantamiento',
            'estado'=>'activo',
            'progreso'=>0,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/v1/proyectos/'.$project->id)
            ->assertStatus(422)
            ->assertJsonPath('message','No se puede eliminar un proyecto originado en una solicitud convertida. Cancélalo o ciérralo para conservar la trazabilidad.');

        $this->assertDatabaseHas('proyectos',['id'=>$project->id,'solicitud_id'=>$request->id]);
    }

    private function fixture(string $suffix):array
    {
        $admin=Usuario::create([
            'nombre'=>'Admin '.$suffix,
            'usuario'=>'admin_'.strtolower(str_replace('-','_',$suffix)),
            'documento'=>'9'.str_pad((string)abs(crc32('ci-'.$suffix)),9,'0',STR_PAD_LEFT),
            'telefono'=>'7'.substr(str_pad((string)abs(crc32('tel-'.$suffix)),8,'0',STR_PAD_LEFT),0,8),
            'password'=>'Prueba1234',
            'rol'=>'superadmin',
            'estado'=>'activo',
        ]);
        $client=Cliente::create([
            'nombre'=>'Cliente '.$suffix,
            'telefono'=>'6'.substr(str_pad((string)abs(crc32('cli-'.$suffix)),8,'0',STR_PAD_LEFT),0,8),
            'estado'=>'informacion_recibida',
        ]);
        $company=Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-'.$suffix,
            'nombre_comercial'=>'Empresa '.$suffix,
            'estado'=>'activo',
        ]);
        return [$admin,$client,$company];
    }

    private function questionnaire():Cuestionario
    {
        $questionnaire=Cuestionario::query()->where('activo',true)->first();
        if(!$questionnaire){
            $this->seed(CuestionarioSeeder::class);
            $questionnaire=Cuestionario::query()->where('activo',true)->firstOrFail();
        }
        return $questionnaire;
    }
}
