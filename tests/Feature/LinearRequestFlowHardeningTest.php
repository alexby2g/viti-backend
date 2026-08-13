<?php

namespace Tests\Feature;

use App\Models\{Cliente,Cuestionario,Empresa,PlanViti,SolicitudSistema,Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LinearRequestFlowHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_change_removes_answers_that_no_longer_apply(): void
    {
        [$request,$initial,$professional] = $this->publicRequestFixture();
        $question32 = $request->cuestionario->secciones()->with('preguntas')->get()->flatMap->preguntas->firstWhere('numero', 32);
        $this->assertNotNull($question32);
        $request->respuestas()->create([
            'pregunta_id'=>$question32->id,
            'respuesta_texto'=>'Respuesta profesional',
            'origen'=>'cliente',
        ]);
        $request->update(['plan_viti_id'=>$professional->id]);

        $this->putJson('/api/v1/publico/solicitudes/'.$request->public_token, [
            'plan_viti_id'=>$initial->id,
            'respuestas'=>[],
        ])->assertOk();

        $this->assertDatabaseMissing('solicitud_respuestas', [
            'solicitud_id'=>$request->id,
            'pregunta_id'=>$question32->id,
        ]);
    }

    public function test_payment_preference_must_match_selected_plan(): void
    {
        [$request,$initial] = $this->publicRequestFixture();

        $this->putJson('/api/v1/publico/solicitudes/'.$request->public_token, [
            'plan_viti_id'=>$initial->id,
            'forma_pago_preferida'=>'tres_partes',
            'respuestas'=>[],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.forma_pago_preferida.0','La forma de pago seleccionada no corresponde al plan VITI elegido.');
    }

    public function test_registration_details_can_be_corrected_from_public_request(): void
    {
        [$request,$initial] = $this->publicRequestFixture();
        $user = Usuario::create([
            'cliente_id'=>$request->cliente_id,
            'nombre'=>'Nombre anterior',
            'usuario'=>'cliente_lineal',
            'documento'=>'12345678',
            'telefono'=>'70001111',
            'password'=>'Prueba1234',
            'rol'=>'cliente',
            'estado'=>'activo',
        ]);

        $this->putJson('/api/v1/publico/solicitudes/'.$request->public_token, [
            'plan_viti_id'=>$initial->id,
            'respuestas'=>[],
            'registro'=>[
                'cliente_nombre'=>'Nombre corregido',
                'cliente_whatsapp'=>'71112222',
                'cliente_ciudad'=>'Trinidad',
                'cliente_direccion'=>'Zona Central',
                'empresa_nombre'=>'Empresa Corregida',
                'empresa_actividad'=>'Servicios técnicos',
                'empresa_ciudad'=>'Trinidad',
                'titulo_sistema'=>'Sistema técnico corregido',
                'resumen'=>'Resumen corregido',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('clientes', [
            'id'=>$request->cliente_id,
            'nombre'=>'Nombre corregido',
            'whatsapp'=>'71112222',
            'ciudad'=>'Trinidad',
        ]);
        $this->assertDatabaseHas('usuarios', ['id'=>$user->id,'nombre'=>'Nombre corregido']);
        $this->assertDatabaseHas('empresas', ['id'=>$request->empresa_id,'nombre_comercial'=>'Empresa Corregida']);
        $this->assertDatabaseHas('solicitudes_sistema', [
            'id'=>$request->id,
            'titulo'=>'Sistema técnico corregido',
            'resumen'=>'Resumen corregido',
        ]);
    }

    public function test_new_request_automatically_uses_only_registered_company(): void
    {
        $this->seed(CuestionarioSeeder::class);
        [$client,$user] = $this->clientAccount('ONE');
        $company = Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-LINEAR-ONE',
            'nombre_comercial'=>'Empresa única',
            'actividad'=>'Servicio técnico',
            'estado'=>'activo',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/mi/solicitud')
            ->assertCreated()
            ->assertJsonPath('data.empresa.id',$company->id);

        $this->assertDatabaseHas('solicitudes_sistema', [
            'id'=>$response->json('data.id'),
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
        ]);
    }

    public function test_new_request_requires_company_choice_when_client_has_multiple_companies(): void
    {
        $this->seed(CuestionarioSeeder::class);
        [$client,$user] = $this->clientAccount('MULTI');
        Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-LINEAR-A',
            'nombre_comercial'=>'Empresa A',
            'estado'=>'activo',
        ]);
        $companyB = Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-LINEAR-B',
            'nombre_comercial'=>'Empresa B',
            'estado'=>'activo',
        ]);

        $this->actingAs($user)->postJson('/api/v1/mi/solicitud')
            ->assertStatus(422)
            ->assertJsonPath('message','Selecciona el negocio al que corresponde esta nueva solicitud.');

        $response = $this->actingAs($user)->postJson('/api/v1/mi/solicitud', ['empresa_id'=>$companyB->id])
            ->assertCreated()
            ->assertJsonPath('data.empresa.id',$companyB->id);

        $this->assertDatabaseHas('solicitudes_sistema', [
            'id'=>$response->json('data.id'),
            'empresa_id'=>$companyB->id,
        ]);
    }

    private function publicRequestFixture(): array
    {
        $this->seed(CuestionarioSeeder::class);
        $questionnaire = Cuestionario::query()->where('activo', true)->firstOrFail();
        $client = Cliente::create([
            'nombre'=>'Cliente lineal',
            'telefono'=>'70001111',
            'whatsapp'=>'70001111',
            'ciudad'=>'Santa Cruz',
            'estado'=>'formulario_en_proceso',
        ]);
        $company = Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-LINEAR-PUBLIC',
            'nombre_comercial'=>'Empresa Lineal',
            'actividad'=>'Servicios',
            'estado'=>'pendiente_revision',
        ]);
        $initial = PlanViti::create([
            'codigo'=>'basico-1800',
            'nombre'=>'VITI Inicial',
            'descripcion'=>'Inicial',
            'precio_proyecto'=>1800,
            'precio_mensual'=>89,
            'precio_anual'=>890,
            'dias_prueba'=>14,
            'modulos'=>['inicio'],
            'max_usuarios'=>3,
            'max_aplicaciones'=>1,
            'activo'=>true,
        ]);
        $professional = PlanViti::create([
            'codigo'=>'profesional-2400',
            'nombre'=>'VITI Profesional',
            'descripcion'=>'Profesional',
            'precio_proyecto'=>2400,
            'precio_mensual'=>129,
            'precio_anual'=>1290,
            'dias_prueba'=>14,
            'modulos'=>['inicio','pagos'],
            'max_usuarios'=>6,
            'max_aplicaciones'=>1,
            'activo'=>true,
        ]);
        $request = SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'codigo'=>'SOL-LINEAR-PUBLIC',
            'public_token'=>Str::random(48),
            'publico_habilitado'=>true,
            'titulo'=>'Sistema Lineal',
            'estado'=>'borrador',
            'prioridad'=>'normal',
            'acuerdo_comercial_requerido'=>true,
        ]);

        return [$request,$initial,$professional];
    }

    private function clientAccount(string $suffix): array
    {
        $client = Cliente::create([
            'nombre'=>'Cliente '.$suffix,
            'telefono'=>'7'.substr(str_pad((string)abs(crc32('client-'.$suffix)),8,'0',STR_PAD_LEFT),0,8),
            'documento'=>'8'.str_pad((string)abs(crc32('doc-'.$suffix)),7,'0',STR_PAD_LEFT),
            'ciudad'=>'Santa Cruz',
            'estado'=>'activo',
        ]);
        $user = Usuario::create([
            'cliente_id'=>$client->id,
            'nombre'=>$client->nombre,
            'usuario'=>'client_'.strtolower($suffix),
            'documento'=>$client->documento,
            'telefono'=>$client->telefono,
            'password'=>'Prueba1234',
            'rol'=>'cliente',
            'estado'=>'activo',
        ]);
        return [$client,$user];
    }
}
