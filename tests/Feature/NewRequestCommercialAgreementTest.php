<?php

namespace Tests\Feature;

use App\Models\{Cliente,Cuestionario,Empresa,PlanViti,SolicitudSistema};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NewRequestCommercialAgreementTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_request_requires_plan_payment_preference_subscription_frequency_and_agreement(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $questionnaire = Cuestionario::query()->where('activo', true)->firstOrFail();
        $client = Cliente::create(['nombre'=>'Cliente nuevo','telefono'=>'73990003','estado'=>'formulario_en_proceso']);
        $company = Empresa::create(['cliente_id'=>$client->id,'codigo'=>'EMP-NEW-AGREEMENT','nombre_comercial'=>'Empresa Nueva','estado'=>'pendiente_revision']);
        $plan = PlanViti::create([
            'codigo'=>'plan-new-agreement-test','nombre'=>'Plan de prueba','descripcion'=>'Plan para probar acuerdo comercial.',
            'precio_proyecto'=>1800,'precio_mensual'=>89,'precio_anual'=>890,'dias_prueba'=>14,
            'modulos'=>['inicio','agenda','ordenes','clientes','equipos','tecnicos','historial','buzon'],'max_usuarios'=>3,'max_aplicaciones'=>1,'activo'=>true,
        ]);
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

        $this->postJson('/api/v1/publico/solicitudes/'.$request->public_token.'/enviar')
            ->assertStatus(422)
            ->assertJsonPath('message','Selecciona si prefieres la suscripción mensual o anual.');

        $request->update(['frecuencia_suscripcion_preferida'=>'anual']);

        $this->postJson('/api/v1/publico/solicitudes/'.$request->public_token.'/enviar')->assertOk();
        $this->assertDatabaseHas('solicitudes_sistema',[
            'id'=>$request->id,
            'plan_viti_id'=>$plan->id,
            'forma_pago_preferida'=>'50_50',
            'frecuencia_suscripcion_preferida'=>'anual',
            'estado'=>'en_revision',
        ]);
    }

    public function test_custom_quote_does_not_force_monthly_or_annual_frequency(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $questionnaire = Cuestionario::query()->where('activo', true)->firstOrFail();
        $client = Cliente::create(['nombre'=>'Cliente especial','telefono'=>'73990004','estado'=>'formulario_en_proceso']);
        $company = Empresa::create(['cliente_id'=>$client->id,'codigo'=>'EMP-CUSTOM-AGREEMENT','nombre_comercial'=>'Empresa Especial','estado'=>'pendiente_revision']);
        $plan = PlanViti::query()->where('codigo','personalizado')->firstOrFail();
        $request = SolicitudSistema::create([
            'empresa_id'=>$company->id,'cliente_id'=>$client->id,'cuestionario_id'=>$questionnaire->id,'plan_viti_id'=>$plan->id,
            'codigo'=>'SOL-CUSTOM-AGREEMENT','public_token'=>Str::random(48),'publico_habilitado'=>true,'titulo'=>'Solicitud especial',
            'estado'=>'borrador','prioridad'=>'normal','forma_pago_preferida'=>'por_definir','acuerdo_comercial_requerido'=>true,
            'declaracion_aceptada'=>true,'declaracion_nombre'=>'Cliente especial','declaracion_fecha'=>now()->toDateString(),
            'acuerdo_comercial_aceptado'=>true,'acuerdo_comercial_nombre'=>'Cliente especial','acuerdo_comercial_fecha'=>now()->toDateString(),
        ]);
        foreach ($questionnaire->secciones()->with('preguntas')->get()->flatMap->preguntas->where('obligatoria', true) as $question) {
            $request->respuestas()->create(['pregunta_id'=>$question->id,'respuesta_texto'=>'Respuesta obligatoria']);
        }

        $this->postJson('/api/v1/publico/solicitudes/'.$request->public_token.'/enviar')->assertOk();
    }
}
