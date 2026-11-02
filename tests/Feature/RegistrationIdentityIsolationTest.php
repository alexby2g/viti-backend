<?php

namespace Tests\Feature;

use App\Models\{Cliente,Empresa,Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegistrationIdentityIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stranger_cannot_claim_a_prospect_or_its_business_by_contact_details(): void
    {
        $prospect = Cliente::create([
            'nombre' => 'Titular Original',
            'telefono' => '70098765',
            'correo' => 'titular@example.com',
            'estado' => 'prospecto',
        ]);
        $company = Empresa::create([
            'cliente_id' => $prospect->id,
            'codigo' => 'EMP-ISOLATED',
            'nombre_comercial' => 'Empresa Privada',
            'estado' => 'pendiente_revision',
        ]);

        $clientCountBefore = Cliente::query()->count();
        $this->postJson('/api/v1/auth/cliente/crear-cuenta', [
            'nombre' => 'Persona No Verificada',
            'correo' => 'titular@example.com',
            'celular' => '70098765',
            'password' => 'Segura12345',
            'password_confirmation' => 'Segura12345',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('usuarios', ['cliente_id' => $prospect->id]);
        $this->assertDatabaseMissing('empresa_usuario', ['empresa_id' => $company->id]);
        $this->assertSame('Titular Original', $prospect->fresh()->nombre);
        $this->assertSame($clientCountBefore, Cliente::query()->count());
    }

    public function test_google_verified_email_does_not_grant_access_using_only_another_persons_phone(): void
    {
        $prospect = Cliente::create([
            'nombre' => 'Contacto Existente',
            'telefono' => '70098766',
            'correo' => 'existente@example.com',
            'estado' => 'prospecto',
        ]);
        $user = Usuario::create([
            'nombre' => 'Persona Google',
            'usuario' => 'persona_google',
            'correo' => 'distinto@example.com',
            'google_sub' => 'google-distinto-prueba',
            'password' => 'Clave123456',
            'rol' => 'cliente',
            'estado' => 'activo',
        ]);
        $token = Str::random(64);
        Cache::put('viti:google:onboarding:'.$token, $user->id, now()->addMinutes(20));

        $this->postJson('/api/v1/auth/cliente/registro', [
            'token' => $token,
            'nombre' => 'Persona Google',
            'celular' => '70098766',
        ])->assertStatus(422);

        $this->assertNull($user->fresh()->cliente_id);
        $this->assertDatabaseMissing('usuarios', ['cliente_id' => $prospect->id]);
    }

    public function test_public_form_cannot_modify_an_existing_customer_without_authentication(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $plan = \App\Models\PlanViti::query()->where('activo', true)->firstOrFail();
        $client = Cliente::create([
            'nombre' => 'Propietario Real',
            'telefono' => '70098767',
            'correo' => 'propietario@example.com',
            'estado' => 'activo',
        ]);
        $owner = Usuario::create([
            'cliente_id' => $client->id,
            'nombre' => 'Propietario',
            'usuario' => 'propietario_real',
            'telefono' => '70098767',
            'correo' => 'propietario@example.com',
            'password' => 'Clave123456',
            'rol' => 'cliente',
            'estado' => 'activo',
        ]);
        $company = Empresa::create([
            'cliente_id' => $client->id,
            'codigo' => 'EMP-OWNER-TEST',
            'nombre_comercial' => 'Negocio del Titular',
            'estado' => 'activo',
        ]);

        $payload = [
            'nombre' => 'Persona Suplantadora',
            'correo' => 'propietario@example.com',
            'telefono' => '70098767',
            'empresa_nombre' => 'Negocio del Titular',
            'titulo_sistema' => 'Sistema de control de prueba',
            'resumen' => 'Necesito registrar las solicitudes de mis clientes.',
            'plan_codigo' => $plan->codigo,
            'forma_pago_preferida' => 'por_definir',
            'frecuencia_suscripcion_preferida' => $plan->precio_mensual !== null && $plan->precio_anual !== null ? 'mensual' : null,
            'terminos_aceptados' => true,
        ];

        $this->postJson('/api/v1/publico/solicitud/enviar', $payload)->assertStatus(422);
        $this->assertDatabaseCount('solicitudes_sistema', 0);
        $this->assertSame('Propietario Real', $client->fresh()->nombre);
        $this->assertSame('activo', $company->fresh()->estado);

        $this->actingAs($owner)->postJson('/api/v1/publico/solicitud/enviar', $payload)
            ->assertCreated()
            ->assertJsonPath('data.cuenta_existente', true);
        $this->assertSame('Propietario Real', $client->fresh()->nombre);
        $this->assertSame('activo', $company->fresh()->estado);
        $this->assertDatabaseCount('solicitudes_sistema', 1);

        // La cuenta del cliente tampoco debe gestionar solicitudes administrativas.
        $this->actingAs($owner)->getJson('/api/v1/solicitudes')->assertStatus(403);
    }
}
