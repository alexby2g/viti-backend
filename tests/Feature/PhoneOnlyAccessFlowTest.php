<?php

namespace Tests\Feature;

use App\Models\{Cliente,Empresa,SolicitudSistema,Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneOnlyAccessFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_client_can_register_with_a_phone_and_no_email(): void
    {
        $response = $this->postJson('/api/v1/auth/cliente/crear-cuenta', [
            'nombre' => 'Elena Sin Correo',
            'celular' => '70123491',
            'password' => 'Segura1234',
            'password_confirmation' => 'Segura1234',
        ])->assertCreated();

        $id = (int) $response->json('usuario.cliente_id');
        $this->assertGreaterThan(0, $id);
        $this->assertDatabaseHas('clientes', ['id' => $id, 'telefono' => '70123491', 'correo' => null]);
        $this->assertDatabaseHas('usuarios', ['cliente_id' => $id, 'telefono' => '70123491', 'correo' => null, 'rol' => 'cliente']);
    }

    public function test_a_public_request_without_email_is_approved_and_invited_by_secure_link(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $plan = collect($this->getJson('/api/v1/publico/solicitud/catalogo')->assertOk()->json('data.planes'))
            ->firstWhere('codigo', 'basico-1800');

        $response = $this->postJson('/api/v1/publico/solicitud/enviar', [
            'nombre' => 'Elena Solicitante',
            'telefono' => '70123492',
            'whatsapp' => '70123492',
            'empresa_nombre' => 'Empresa Sin Correo',
            'titulo_sistema' => 'Gestión de reservas',
            'resumen' => 'Necesito organizar reservas para mi negocio.',
            'plan_codigo' => $plan['codigo'],
            'forma_pago_preferida' => 'por_definir',
            'frecuencia_suscripcion_preferida' => $plan['precio_mensual'] !== null && $plan['precio_anual'] !== null ? 'mensual' : null,
            'declaracion_aceptada' => true,
            'declaracion_nombre' => 'Elena Solicitante',
            'declaracion_fecha' => now()->toDateString(),
            'acuerdo_comercial_aceptado' => true,
            'acuerdo_comercial_nombre' => 'Elena Solicitante',
            'acuerdo_comercial_fecha' => now()->toDateString(),
            'terminos_aceptados' => true,
            'respuestas' => [],
        ])->assertCreated()->assertJsonPath('data.estado', 'en_revision');

        $solicitud = SolicitudSistema::query()->where('codigo', $response->json('data.codigo'))->firstOrFail();
        $this->assertNull($solicitud->cliente->correo);

        $admin = Usuario::create([
            'nombre' => 'Administrador Prueba',
            'usuario' => 'admin_phone_test',
            'telefono' => '70123495',
            'password' => 'Segura1234',
            'rol' => 'superadmin',
            'estado' => 'activo',
        ]);
        $approved = $this->actingAs($admin)->postJson('/api/v1/solicitudes/'.$solicitud->id.'/aprobar')
            ->assertOk()
            ->assertJsonPath('data.solicitud.estado', 'aprobada');

        $url = (string) $approved->json('data.acceso.url');
        $this->assertStringContainsString('/registro-cliente/', $url);
        $this->assertFalse((bool) $approved->json('data.acceso.email_enviado'));

        $token = (string) substr($url, strrpos($url, '/') + 1);
        $this->getJson('/api/v1/publico/registro/'.$token)->assertOk();
        $this->postJson('/api/v1/publico/registro/'.$token, [
            'usuario' => 'elena_viti',
            'password' => 'AccesoSeguro123',
            'password_confirmation' => 'AccesoSeguro123',
        ])->assertCreated();

        $this->assertDatabaseHas('usuarios', [
            'cliente_id' => $solicitud->cliente_id,
            'correo' => null,
            'usuario' => 'elena_viti',
            'rol' => 'cliente',
        ]);
        $owner = Usuario::query()->where('usuario', 'elena_viti')->firstOrFail();
        $this->assertDatabaseHas('empresa_usuario', [
            'empresa_id' => $solicitud->empresa_id,
            'usuario_id' => $owner->id,
            'rol_negocio' => 'propietario',
            'activo' => true,
        ]);
        $this->actingAs($owner)->getJson('/api/v1/solicitudes')->assertForbidden();
    }

    public function test_an_existing_phone_cannot_claim_another_persons_prospect(): void
    {
        $cliente = Cliente::create([
            'nombre' => 'Cliente Original',
            'telefono' => '70123493',
            'estado' => 'prospecto',
        ]);
        $empresa = Empresa::create([
            'cliente_id' => $cliente->id,
            'codigo' => 'EMP-PHONE-ISOLATION',
            'nombre_comercial' => 'Negocio Original',
            'estado' => 'pendiente_revision',
        ]);

        $this->postJson('/api/v1/auth/cliente/crear-cuenta', [
            'nombre' => 'Persona No Verificada',
            'celular' => '70123493',
            'password' => 'Segura1234',
            'password_confirmation' => 'Segura1234',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('usuarios', ['cliente_id' => $cliente->id]);
        $this->assertDatabaseMissing('empresa_usuario', ['empresa_id' => $empresa->id]);
    }
}
