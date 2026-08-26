<?php

namespace Tests\Feature;

use App\Models\{Aplicacion,Cliente,Empresa,PlanViti,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationMoldEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_payload_exposes_plan_modules_and_effective_app_modules(): void
    {
        [$admin, $app] = $this->fixture('editor-read');

        $this->actingAs($admin)
            ->getJson("/api/v1/aplicaciones/{$app->id}?editor=1")
            ->assertOk()
            ->assertJsonPath('data.plan.codigo', 'base')
            ->assertJsonPath('data.configuracion.heredar_modulos_plan', false)
            ->assertJsonPath('data.configuracion.modulos.0', 'clientes')
            ->assertJsonPath('data.configuracion.modulos.1', 'equipos')
            ->assertJsonPath('data.catalogo_modulos.0.key', 'inicio');
    }

    public function test_editor_rejects_module_outside_company_plan(): void
    {
        [$admin, $app] = $this->fixture('editor-plan');

        $this->actingAs($admin)
            ->putJson("/api/v1/aplicaciones/{$app->id}", [
                'editor' => true,
                'heredar_modulos_plan' => false,
                'modulos' => ['clientes', 'inventario'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes habilitar módulos que no pertenecen al plan VITI de la empresa.');

        $this->assertSame([], (array) $app->fresh()->configuracion);
    }

    public function test_editor_saves_branding_modules_and_audit_without_changing_delivery_state(): void
    {
        [$admin, $app] = $this->fixture('editor-save');

        $this->actingAs($admin)
            ->putJson("/api/v1/aplicaciones/{$app->id}", [
                'editor' => true,
                'heredar_modulos_plan' => false,
                'modulos' => ['clientes', 'equipos'],
                'branding' => [
                    'nombre' => 'Electrofrío Pro',
                    'logo_url' => 'https://example.com/logo.png',
                    'icono' => 'ac_unit',
                    'color_principal' => '#123456',
                    'color_secundario' => '#654321',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.configuracion.heredar_modulos_plan', false)
            ->assertJsonPath('data.configuracion.modulos.0', 'clientes')
            ->assertJsonPath('data.configuracion.branding.nombre', 'Electrofrío Pro');

        $saved = $app->fresh();
        $this->assertSame('Electrofrío Pro', $saved->configuracion['branding']['nombre']);
        $this->assertFalse($saved->acceso_cliente);
        $this->assertDatabaseHas('auditoria', [
            'accion' => 'aplicacion_moldeada',
            'entidad_id' => $app->id,
        ]);
    }

    public function test_normal_update_without_editor_keeps_existing_application_flow(): void
    {
        [$admin, $app] = $this->fixture('editor-normal');
        $app->update(['configuracion' => ['heredar_modulos_plan' => false, 'modulos' => ['clientes']]]);

        $this->actingAs($admin)
            ->putJson("/api/v1/aplicaciones/{$app->id}", [
                'empresa_id' => $app->empresa_id,
                'nombre' => 'Sistema actualizado',
            ])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Sistema actualizado');

        $this->assertSame(['clientes'], $app->fresh()->configuracion['modulos']);
    }

    private function fixture(string $suffix): array
    {
        $admin = Usuario::create([
            'nombre' => 'Admin Moldeador',
            'usuario' => 'admin_'.$suffix,
            'documento' => '90'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'telefono' => '70'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'password' => 'PruebaSegura123',
            'rol' => 'administrador',
            'estado' => 'activo',
        ]);

        $client = Cliente::create([
            'nombre' => 'Cliente Moldeador',
            'telefono' => '71'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'estado' => 'informacion_recibida',
        ]);

        $plan = PlanViti::create([
            'codigo' => 'base',
            'nombre' => 'Base',
            'precio_proyecto' => 0,
            'precio_mensual' => 0,
            'precio_anual' => 0,
            'dias_prueba' => 0,
            'modulos' => ['inicio', 'agenda', 'clientes', 'equipos'],
            'max_usuarios' => 10,
            'max_aplicaciones' => 5,
            'activo' => true,
        ]);

        $company = Empresa::create([
            'cliente_id' => $client->id,
            'plan_viti_id' => $plan->id,
            'codigo' => 'EMP-'.strtoupper($suffix),
            'nombre_comercial' => 'Empresa Moldeador '.$suffix,
            'estado' => 'activo',
        ]);

        $app = Aplicacion::create([
            'empresa_id' => $company->id,
            'nombre' => 'Sistema Moldeador '.$suffix,
            'slug' => 'sistema-moldeador-'.$suffix,
            'version' => '1.0.0',
            'tipo' => 'web',
            'entorno' => 'beta',
            'estado' => 'en_pruebas',
            'acceso_cliente' => false,
            'configuracion' => $suffix === 'editor-read'
                ? ['heredar_modulos_plan' => false, 'modulos' => ['clientes', 'equipos']]
                : [],
        ]);

        return [$admin, $app];
    }
}
