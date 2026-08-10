<?php

namespace Tests\Feature;

use App\Models\{Cliente,Cuestionario,Empresa,PlanViti,SolicitudSistema};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NewRequestCommercialAgreementTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_request_requires_plan_payment_preference_and_agreement(): void
    {
        $questionnaire = Cuestionario::query()->where('activo', true)->firstOrFail();
        $client = Cliente::create(['nombre'=>'Cliente nuevo','telefono'=>'73990003','estado'=>'formulario_en_proceso']);
        $company = Empresa::create(['cliente_id'=>$client->id,'codigo'=>'EMP-NEW-AGREEMENT','nombre_comercial'=>'Empresa Nueva','estado'=>'pendiente_revision']);
        $plan = PlanViti::query()->where('activo', true)->whereNotNull('precio_proyecto')->firstOrFail();
        $request = SolicitudSistema::create([
            'empresa_id'=>$company->id,'cliente_id'=>$client->id,'cuestionario_id'=>$questionnaire->id,
            'codigo'=>'SOL-NEW-AGREEMENT','public_token'=>Str::random(48),'publico_habilitado'=>true,'titulo'=>'Solicitud nueva',
            'estado'=>'borrador','prioridad'=>'normal','acuerdo_comercial_requerido'=>true,
            'declaracion_aceptada'=>true,'declaracion_nombre'=>'Cliente nuevo','declaracion_fecha'=>now()->toDateString(),
        ]);
        foreach ($questionnaire->secciones()->with('preguntas')->get()->flatMap->preguntas->where('obligatoria', true) as $question) {
            $request->respuestas()->create(['pregunta_id'=>$question->id,'respuesta_texto'=>'Respuesta obligatoria']);
        }

        $this->postJson('/api/v1/publico/solicitudes/'.$request->public_token.'/enviar')->assertStatus(422);

        $request->update([
            'plan_viti_id'=>$plan->id,
            'forma_pago_preferida'=>'50_50',
            'acuerdo_comercial_aceptado'=>true,
            'acuerdo_comercial_nombre'=>'Cliente nuevo',
            'acuerdo_comercial_fecha'=>now()->toDateString(),
        ]);

        $this->postJson('/api/v1/publico/solicitudes/'.$request->public_token.'/enviar')->assertOk();
    }
}
