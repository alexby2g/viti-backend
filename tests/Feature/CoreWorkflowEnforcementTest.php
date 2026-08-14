<?php

namespace Tests\Feature;

use App\Models\{Cliente,Cuestionario,Empresa,Proyecto,SolicitudSistema,Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoreWorkflowEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Usuario
    {
        return Usuario::query()->firstOrCreate(
            ['usuario'=>'admin_flujo'],
            [
                'nombre'=>'Admin Flujo','documento'=>'90123456','telefono'=>'70012345',
                'password'=>'Prueba123456','rol'=>'superadmin','estado'=>'activo',
            ]
        );
    }

    private function base(): array
    {
        $this->seed(CuestionarioSeeder::class);
        $cliente=Cliente::create(['nombre'=>'Cliente Flujo','telefono'=>'73990001','estado'=>'informacion_recibida']);
        $empresa=Empresa::create([
            'cliente_id'=>$cliente->id,'codigo'=>'EMP-FLOW','nombre_comercial'=>'Empresa Flujo',
            'actividad'=>'Servicios técnicos','estado'=>'activo',
        ]);
        $cuestionario=Cuestionario::query()->where('activo',true)->firstOrFail();
        return [$cliente,$empresa,$cuestionario];
    }

    public function test_admin_cannot_skip_request_workflow_through_generic_update(): void
    {
        [$cliente,$empresa,$cuestionario]=$this->base();
        $solicitud=SolicitudSistema::create([
            'empresa_id'=>$empresa->id,'cliente_id'=>$cliente->id,'cuestionario_id'=>$cuestionario->id,
            'codigo'=>'SOL-FLOW-1','public_token'=>Str::random(48),'publico_habilitado'=>true,
            'titulo'=>'Solicitud flujo','estado'=>'borrador','prioridad'=>'normal',
        ]);

        $this->actingAs($this->admin())->putJson('/api/v1/solicitudes/'.$solicitud->id,[
            'empresa_id'=>$empresa->id,'cliente_id'=>$cliente->id,'cuestionario_id'=>$cuestionario->id,
            'titulo'=>'Solicitud flujo','estado'=>'aprobada','prioridad'=>'normal',
        ])->assertStatus(422)->assertJsonValidationErrors('estado');

        $this->assertDatabaseHas('solicitudes_sistema',['id'=>$solicitud->id,'estado'=>'borrador']);
    }

    public function test_admin_can_move_request_through_legal_transition_and_receives_next_states(): void
    {
        [$cliente,$empresa,$cuestionario]=$this->base();
        $solicitud=SolicitudSistema::create([
            'empresa_id'=>$empresa->id,'cliente_id'=>$cliente->id,'cuestionario_id'=>$cuestionario->id,
            'codigo'=>'SOL-FLOW-2','public_token'=>Str::random(48),'publico_habilitado'=>true,
            'titulo'=>'Solicitud flujo dos','estado'=>'en_revision','prioridad'=>'normal',
        ]);

        $this->actingAs($this->admin())->putJson('/api/v1/solicitudes/'.$solicitud->id,[
            'empresa_id'=>$empresa->id,'cliente_id'=>$cliente->id,'cuestionario_id'=>$cuestionario->id,
            'titulo'=>'Solicitud flujo dos','estado'=>'aprobada','prioridad'=>'normal',
        ])->assertOk()->assertJsonPath('data.workflow.actual','aprobada')
          ->assertJsonPath('data.workflow.permitidos.0','convertida');
    }

    public function test_project_update_cannot_skip_phase_or_revive_cancelled_project(): void
    {
        [$cliente,$empresa]=$this->base();
        $admin=$this->admin();
        $proyecto=Proyecto::create([
            'empresa_id'=>$empresa->id,'cliente_id'=>$cliente->id,'codigo'=>'PRO-FLOW-1','nombre'=>'Proyecto Flujo',
            'fase'=>'levantamiento','estado'=>'activo','progreso'=>0,
        ]);

        $this->actingAs($admin)->putJson('/api/v1/proyectos/'.$proyecto->id,[
            'empresa_id'=>$empresa->id,'cliente_id'=>$cliente->id,'nombre'=>'Proyecto Flujo',
            'fase'=>'implementacion','estado'=>'activo','progreso'=>50,
        ])->assertStatus(422)->assertJsonValidationErrors('fase');

        $proyecto->update(['estado'=>'cancelado']);
        $this->actingAs($admin)->putJson('/api/v1/proyectos/'.$proyecto->id,[
            'empresa_id'=>$empresa->id,'cliente_id'=>$cliente->id,'nombre'=>'Proyecto Flujo',
            'fase'=>'levantamiento','estado'=>'activo','progreso'=>0,
        ])->assertStatus(422)->assertJsonValidationErrors('estado');
    }

    public function test_progress_endpoint_also_obeys_project_phase_machine(): void
    {
        [$cliente,$empresa]=$this->base();
        $admin=$this->admin();
        $proyecto=Proyecto::create([
            'empresa_id'=>$empresa->id,'cliente_id'=>$cliente->id,'codigo'=>'PRO-FLOW-2','nombre'=>'Proyecto Avances',
            'fase'=>'desarrollo','estado'=>'activo','progreso'=>40,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/proyectos/'.$proyecto->id.'/avances',[
            'fase'=>'implementacion','area'=>'backend','titulo'=>'Intento de salto','progreso'=>80,
        ])->assertStatus(422)->assertJsonValidationErrors('fase');

        $this->actingAs($admin)->postJson('/api/v1/proyectos/'.$proyecto->id.'/avances',[
            'fase'=>'beta','area'=>'qa','titulo'=>'Entrada a beta','progreso'=>60,
        ])->assertCreated()->assertJsonPath('workflow.fase.actual','beta')
          ->assertJsonPath('workflow.fase.permitidos.0','pruebas');
    }
}
