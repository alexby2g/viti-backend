<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_direct_client_registration_is_closed(): void
    {
        $this->postJson('/api/v1/auth/cliente/registro', [
            'nombre' => 'Registro no autorizado',
            'usuario' => 'registro_abierto',
            'telefono' => '70000111',
            'ci' => '90000111',
            'password' => 'Prueba123456',
            'password_confirmation' => 'Prueba123456',
        ])
            ->assertStatus(410)
            ->assertJsonPath('codigo', 'registro_por_invitacion');

        $this->assertDatabaseMissing('usuarios', ['usuario' => 'registro_abierto']);
    }

    public function test_failed_login_is_audited_without_storing_plain_identifier(): void
    {
        $user = Usuario::create([
            'nombre' => 'Usuario Seguridad',
            'usuario' => 'seguridad_test',
            'documento' => '90000222',
            'telefono' => '70000222',
            'password' => 'Correcta123456',
            'rol' => 'cliente',
            'estado' => 'activo',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'acceso' => 'seguridad_test',
            'password' => 'Incorrecta123456',
        ])->assertStatus(422);

        $audit = \DB::table('auditoria')->where('accion', 'inicio_sesion_fallido')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame((string) $user->id, (string) $audit->entidad_id);
        $this->assertStringNotContainsString('seguridad_test', (string) $audit->datos);
        $this->assertStringContainsString('identificador_hash', (string) $audit->datos);
    }

    public function test_api_responses_include_hardening_headers(): void
    {
        $response = $this->getJson('/api/health')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_production_session_is_not_configured_for_thirty_days(): void
    {
        $render = file_get_contents(base_path('render.yaml'));
        $this->assertIsString($render);
        $this->assertStringContainsString("- key: SESSION_LIFETIME\n        value: \"720\"", $render);
        $this->assertStringNotContainsString("- key: SESSION_LIFETIME\n        value: \"43200\"", $render);
    }
}
