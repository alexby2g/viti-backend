<?php

namespace Tests\Feature;

use App\Models\{Cliente,Cuestionario,Empresa,PlanViti,SolicitudSistema};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicSolicitudOptionalResponsesTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_can_be_saved_with_empty_optional_answers(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $questionnaire = Cuestionario::query()->where('activo', true)->firstOrFail();
        $client = Cliente::create([
            'nombre'=>'Cliente prueba',
            'telefono'=>'73991111',
            'estado'=>'formulario_en_proceso',
        ]);
        $company = Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-OPTIONAL-ANSWERS',
            'nombre_comercial'=>'Empresa prueba',
            'estado'=>'pendiente_revision',
        ]);
        $plan = PlanViti::query()->where('activo', true)->firstOrFail();
        $request = SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'codigo'=>'SOL-OPTIONAL-ANSWERS',
            'public_token'=>Str::random(48),
            'publico_habilitado'=>true,
            'titulo'=>'Solicitud de prueba',
            'estado'=>'borrador',
            'prioridad'=>'normal',
            'acuerdo_comercial_requerido'=>true,
        ]);

        $this->putJson('/api/v1/publico/solicitudes/'.$request->public_token, [
            'respuestas'=>[],
            'plan_viti_id'=>$plan->id,
        ])
            ->assertOk()
            ->assertJsonPath('message','Tus respuestas fueron guardadas.');

        $this->assertDatabaseHas('solicitudes_sistema', [
            'id'=>$request->id,
            'plan_viti_id'=>$plan->id,
        ]);
        $this->assertDatabaseCount('solicitud_respuestas', 0);
    }

    public function test_optional_answers_key_may_be_omitted_when_only_commercial_data_changes(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $questionnaire = Cuestionario::query()->where('activo', true)->firstOrFail();
        $client = Cliente::create([
            'nombre'=>'Cliente sin respuestas',
            'telefono'=>'73992222',
            'estado'=>'formulario_en_proceso',
        ]);
        $company = Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-NO-ANSWERS-KEY',
            'nombre_comercial'=>'Empresa sin respuestas',
            'estado'=>'pendiente_revision',
        ]);
        $plan = PlanViti::query()->where('activo', true)->firstOrFail();
        $request = SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'codigo'=>'SOL-NO-ANSWERS-KEY',
            'public_token'=>Str::random(48),
            'publico_habilitado'=>true,
            'titulo'=>'Solicitud comercial',
            'estado'=>'borrador',
            'prioridad'=>'normal',
            'acuerdo_comercial_requerido'=>true,
        ]);

        $this->putJson('/api/v1/publico/solicitudes/'.$request->public_token, [
            'plan_viti_id'=>$plan->id,
        ])->assertOk();

        $this->assertDatabaseHas('solicitudes_sistema', [
            'id'=>$request->id,
            'plan_viti_id'=>$plan->id,
        ]);
    }
}
