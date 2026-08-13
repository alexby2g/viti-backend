<?php

namespace Tests\Feature;

use App\Models\{Cliente,Cuestionario,Empresa,PlanViti,SolicitudSistema};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicDraftRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_save_increments_draft_revision(): void
    {
        $request = $this->draftRequest();

        $response = $this->putJson('/api/v1/publico/solicitudes/'.$request->public_token, [
            'base_revision'=>0,
            'registro'=>['titulo_sistema'=>'Título actualizado'],
        ])->assertOk()
            ->assertJsonPath('draft.revision', 1);

        $this->assertSame('1', $response->headers->get('X-VITI-Draft-Revision'));
        $this->assertDatabaseHas('solicitudes_sistema', [
            'id'=>$request->id,
            'titulo'=>'Título actualizado',
            'draft_revision'=>1,
        ]);
    }

    public function test_stale_device_cannot_overwrite_newer_draft(): void
    {
        $request = $this->draftRequest();

        $this->putJson('/api/v1/publico/solicitudes/'.$request->public_token, [
            'base_revision'=>0,
            'registro'=>['titulo_sistema'=>'Guardado desde dispositivo A'],
        ])->assertOk()->assertJsonPath('draft.revision', 1);

        $this->putJson('/api/v1/publico/solicitudes/'.$request->public_token, [
            'base_revision'=>0,
            'registro'=>['titulo_sistema'=>'Intento viejo desde dispositivo B'],
        ])->assertStatus(409)
            ->assertJsonPath('current_revision', 1)
            ->assertJsonPath('message', 'Hay cambios más recientes guardados desde otro dispositivo o pestaña.');

        $this->assertDatabaseHas('solicitudes_sistema', [
            'id'=>$request->id,
            'titulo'=>'Guardado desde dispositivo A',
            'draft_revision'=>1,
        ]);
    }

    public function test_old_tab_cannot_modify_request_after_it_was_submitted(): void
    {
        $request = $this->draftRequest();
        $plan = PlanViti::query()->where('codigo','basico-1800')->firstOrFail();
        $request->update([
            'plan_viti_id'=>$plan->id,
            'forma_pago_preferida'=>'50_50',
            'frecuencia_suscripcion_preferida'=>'mensual',
            'declaracion_aceptada'=>true,
            'declaracion_nombre'=>'Cliente prueba',
            'declaracion_fecha'=>now()->toDateString(),
            'acuerdo_comercial_aceptado'=>true,
            'acuerdo_comercial_nombre'=>'Cliente prueba',
            'acuerdo_comercial_fecha'=>now()->toDateString(),
        ]);

        $this->postJson('/api/v1/publico/solicitudes/'.$request->public_token.'/enviar', [
            'base_revision'=>0,
        ])->assertOk()->assertJsonPath('draft.revision', 1);

        $this->putJson('/api/v1/publico/solicitudes/'.$request->public_token, [
            'base_revision'=>0,
            'registro'=>['titulo_sistema'=>'No debe guardarse'],
        ])->assertStatus(409)
            ->assertJsonPath('estado','en_revision');

        $this->assertDatabaseHas('solicitudes_sistema', [
            'id'=>$request->id,
            'estado'=>'en_revision',
            'draft_revision'=>1,
        ]);
        $this->assertDatabaseMissing('solicitudes_sistema', [
            'id'=>$request->id,
            'titulo'=>'No debe guardarse',
        ]);
    }

    private function draftRequest(): SolicitudSistema
    {
        $this->seed(CuestionarioSeeder::class);
        $questionnaire = Cuestionario::query()->where('activo', true)->firstOrFail();
        $client = Cliente::create([
            'nombre'=>'Cliente prueba',
            'telefono'=>'70009991',
            'whatsapp'=>'70009991',
            'ciudad'=>'Santa Cruz',
            'estado'=>'formulario_en_proceso',
        ]);
        $company = Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-DRAFT-REV',
            'nombre_comercial'=>'Empresa Draft',
            'actividad'=>'Servicios',
            'estado'=>'pendiente_revision',
        ]);

        return SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'codigo'=>'SOL-DRAFT-REV',
            'public_token'=>Str::random(48),
            'publico_habilitado'=>true,
            'titulo'=>'Sistema inicial',
            'estado'=>'borrador',
            'prioridad'=>'normal',
            'acuerdo_comercial_requerido'=>true,
        ]);
    }
}
