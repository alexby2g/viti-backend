<?php

namespace Tests\Feature;

use App\Models\Cuestionario;
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessInvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_public_application_flow_is_retired(): void
    {
        $this->postJson('/api/v1/publico/solicitudes', [
            'nombre' => 'Roberto Pérez',
            'correo' => 'roberto@example.com',
            'telefono' => '7777789',
            'empresa_nombre' => 'Trinicenter',
        ])->assertStatus(410);

        $this->getJson('/api/v1/publico/solicitudes/demo-token')
            ->assertStatus(410);

        $this->putJson('/api/v1/publico/solicitudes/demo-token', [])
            ->assertStatus(410);

        $this->postJson('/api/v1/publico/solicitudes/demo-token/enviar', [])
            ->assertStatus(410);
    }

    public function test_official_viti_application_flow_creates_a_reviewable_request_without_account_access(): void
    {
        $this->seed(CuestionarioSeeder::class);

        $catalog = $this->getJson('/api/v1/publico/solicitud/catalogo')
            ->assertOk()
            ->json('data');

        // Keep the integration test on a standard plan so it never depends on
        // database insertion order placing the custom quotation first.
        $plan = collect($catalog['planes'])->firstWhere('codigo', 'basico-1800')
            ?? collect($catalog['planes'])->first();

        $questionnaire = Cuestionario::query()
            ->where('activo', true)
            ->with(['secciones.preguntas'])
            ->latest('id')
            ->firstOrFail();

        $answers = collect($questionnaire->secciones)
            ->flatMap(fn ($section) => $section->preguntas)
            ->filter(fn ($question) => $question->obligatoria)
            ->map(fn ($question) => [
                'pregunta_id' => $question->id,
                'valor' => 'Respuesta de prueba',
            ])->values()->all();

        $payload = [
            'nombre' => 'Roberto Pérez',
            'correo' => 'roberto-'.uniqid().'@example.com',
            'telefono' => '7777789',
            'whatsapp' => '7777789',
            'ciudad' => 'Santa Cruz',
            'empresa_nombre' => 'Trinicenter '.uniqid(),
            'empresa_actividad' => 'Servicios técnicos',
            'empresa_telefono' => '7777789',
            'empresa_whatsapp' => '7777789',
            'empresa_ciudad' => 'Santa Cruz',
            'titulo_sistema' => 'Clientes y pagos',
            'resumen' => 'Solicitud de prueba del flujo oficial.',
            'plan_codigo' => $plan['codigo'],
            'forma_pago_preferida' => in_array($plan['codigo'], ['personalizado'], true) ? 'por_definir' : 'contado',
            'frecuencia_suscripcion_preferida' => ($plan['precio_mensual'] !== null && $plan['precio_anual'] !== null) ? 'mensual' : null,
            'declaracion_aceptada' => true,
            'declaracion_nombre' => 'Roberto Pérez',
            'declaracion_fecha' => now()->toDateString(),
            'acuerdo_comercial_aceptado' => true,
            'acuerdo_comercial_nombre' => 'Roberto Pérez',
            'acuerdo_comercial_fecha' => now()->toDateString(),
            'respuestas' => $answers,
        ];

        $response = $this->postJson('/api/v1/publico/solicitud/enviar', $payload)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'en_revision');

        $this->assertDatabaseHas('solicitudes_sistema', [
            'codigo' => $response->json('data.codigo'),
            'estado' => 'en_revision',
        ]);

        $this->assertDatabaseMissing('usuarios', [
            'correo' => $payload['correo'],
        ]);
    }

    public function test_request_first_then_account_reuses_the_same_client_and_links_the_request(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $catalog = $this->getJson('/api/v1/publico/solicitud/catalogo')->assertOk()->json('data');
        $plan = collect($catalog['planes'])->firstWhere('codigo', 'basico-1800') ?? collect($catalog['planes'])->first();
        $email = 'request-first-'.uniqid().'@example.com';
        $phone = '7777791';

        $requestResponse = $this->postJson('/api/v1/publico/solicitud/enviar', [
            'nombre'=>'Carlos Cliente','correo'=>$email,'telefono'=>$phone,'whatsapp'=>$phone,
            'empresa_nombre'=>'Negocio Request First','titulo_sistema'=>'Sistema de pedidos',
            'resumen'=>'Quiero ordenar pedidos y entregas desde un solo lugar.',
            'plan_codigo'=>$plan['codigo'],'forma_pago_preferida'=>'por_definir',
            'frecuencia_suscripcion_preferida'=>($plan['precio_mensual']!==null&&$plan['precio_anual']!==null)?'mensual':null,
            'declaracion_aceptada'=>true,'declaracion_nombre'=>'Carlos Cliente','declaracion_fecha'=>now()->toDateString(),
            'acuerdo_comercial_aceptado'=>true,'acuerdo_comercial_nombre'=>'Carlos Cliente','acuerdo_comercial_fecha'=>now()->toDateString(),
            'terminos_aceptados'=>true,'respuestas'=>[],
        ])->assertCreated();

        $codigo = $requestResponse->json('data.codigo');
        $solicitud = \App\Models\SolicitudSistema::query()->where('codigo',$codigo)->firstOrFail();
        $clientIdBefore = $solicitud->cliente_id;

        $accountResponse = $this->postJson('/api/v1/auth/cliente/crear-cuenta', [
            'nombre'=>'Carlos Cliente','correo'=>$email,'celular'=>$phone,'whatsapp'=>$phone,
            'password'=>'Clave2026','password_confirmation'=>'Clave2026',
        ])->assertCreated();

        $this->assertSame($clientIdBefore, (int) $accountResponse->json('usuario.cliente_id'));
        $this->assertDatabaseCount('clientes', 1);
        $this->assertDatabaseHas('usuarios', ['correo'=>$email,'cliente_id'=>$clientIdBefore,'rol'=>'cliente']);
        $this->assertDatabaseHas('solicitudes_sistema', ['codigo'=>$codigo,'cliente_id'=>$clientIdBefore]);
    }

    public function test_account_first_then_public_request_uses_the_existing_client_account(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $catalog = $this->getJson('/api/v1/publico/solicitud/catalogo')->assertOk()->json('data');
        $plan = collect($catalog['planes'])->firstWhere('codigo', 'basico-1800') ?? collect($catalog['planes'])->first();
        $email = 'account-first-'.uniqid().'@example.com';
        $phone = '7777792';

        $account = $this->postJson('/api/v1/auth/cliente/crear-cuenta', [
            'nombre'=>'Ana Cliente','correo'=>$email,'celular'=>$phone,'whatsapp'=>$phone,
            'password'=>'Clave2026','password_confirmation'=>'Clave2026',
        ])->assertCreated();
        $clientId = (int) $account->json('usuario.cliente_id');

        $response = $this->postJson('/api/v1/publico/solicitud/enviar', [
            'nombre'=>'Ana Cliente','correo'=>$email,'telefono'=>$phone,'whatsapp'=>$phone,
            'empresa_nombre'=>'Negocio Account First','titulo_sistema'=>'Sistema de reservas',
            'resumen'=>'Quiero recibir reservas y controlar horarios de atención.',
            'plan_codigo'=>$plan['codigo'],'forma_pago_preferida'=>'por_definir',
            'frecuencia_suscripcion_preferida'=>($plan['precio_mensual']!==null&&$plan['precio_anual']!==null)?'mensual':null,
            'declaracion_aceptada'=>true,'declaracion_nombre'=>'Ana Cliente','declaracion_fecha'=>now()->toDateString(),
            'acuerdo_comercial_aceptado'=>true,'acuerdo_comercial_nombre'=>'Ana Cliente','acuerdo_comercial_fecha'=>now()->toDateString(),
            'terminos_aceptados'=>true,'respuestas'=>[],
        ])->assertCreated()->assertJsonPath('data.cuenta_existente', true);

        $this->assertDatabaseCount('clientes', 1);
        $this->assertDatabaseHas('solicitudes_sistema', ['codigo'=>$response->json('data.codigo'),'cliente_id'=>$clientId]);
        $this->assertDatabaseHas('empresas', ['cliente_id'=>$clientId,'nombre_comercial'=>'Negocio Account First']);
    }

}
