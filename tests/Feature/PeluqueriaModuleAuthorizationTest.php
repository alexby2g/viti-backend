<?php

namespace Tests\Feature;

use App\Models\{Aplicacion,CatalogoAplicacion,Empresa,PlanViti,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeluqueriaModuleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(
        string $suffix,
        string $planCode = 'empresa-2500',
        string $role = 'propietario',
        ?array $permissions = null,
        ?array $configuration = null,
    ): array {
        $plan = PlanViti::query()->where('codigo', $planCode)->firstOrFail();
        $catalog = CatalogoAplicacion::query()->where('clave', 'peluqueria')->firstOrFail();
        $company = Empresa::create([
            'plan_viti_id' => $plan->id,
            'codigo' => 'HAIR-'.$suffix,
            'nombre_comercial' => 'Peluquería '.$suffix,
            'estado' => 'activo',
            'configuracion' => $configuration,
        ]);
        $app = Aplicacion::create([
            'empresa_id' => $company->id,
            'catalogo_aplicacion_id' => $catalog->id,
            'nombre' => 'Peluquería '.$suffix,
            'slug' => 'peluqueria-'.strtolower($suffix),
            'version' => '1.0.0',
            'tipo' => 'web',
            'entorno' => 'produccion',
            'estado' => 'activo',
            'acceso_cliente' => true,
            'entregado_at' => now(),
        ]);
        $user = Usuario::create([
            'nombre' => 'Usuario '.$suffix,
            'usuario' => 'hair_'.strtolower($suffix),
            'documento' => '7'.str_pad((string) abs(crc32('doc-'.$suffix)), 9, '0', STR_PAD_LEFT),
            'telefono' => '6'.substr(str_pad((string) abs(crc32('phone-'.$suffix)), 8, '0', STR_PAD_LEFT), 0, 8),
            'password' => 'Prueba1234',
            'rol' => 'cliente',
            'estado' => 'activo',
        ]);
        $company->usuarios()->attach($user->id, [
            'rol_negocio' => $role,
            'permisos' => $permissions === null ? null : json_encode($permissions),
            'activo' => true,
        ]);

        return compact('plan','catalog','company','app','user');
    }

    private function headers(array $tenant): array
    {
        return ['X-VITI-Empresa' => (string) $tenant['company']->id];
    }

    public function test_peluqueria_can_use_an_allowed_module(): void
    {
        $tenant = $this->tenant('ALLOWED');

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/peluqueria/clientes', $this->headers($tenant))
            ->assertOk()
            ->assertJsonPath('data', []);

        // `servicios` conserva la clave canónica actual `ordenes`.
        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/peluqueria/servicios', $this->headers($tenant))
            ->assertOk();
    }

    public function test_company_disabled_module_is_blocked_in_backend(): void
    {
        $tenant = $this->tenant('COMPANY-OFF', configuration: [
            'modulos' => ['clientes' => ['habilitado' => false]],
        ]);

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/peluqueria/clientes', $this->headers($tenant))
            ->assertForbidden();
    }

    public function test_user_without_module_permission_is_blocked(): void
    {
        $tenant = $this->tenant(
            'NO-PERMISSION',
            role: 'empleado',
            permissions: ['inicio','agenda','ordenes']
        );

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/peluqueria/clientes', $this->headers($tenant))
            ->assertForbidden();

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/peluqueria/atenciones', $this->headers($tenant))
            ->assertOk();
    }

    public function test_module_not_allowed_by_plan_is_blocked(): void
    {
        $tenant = $this->tenant('PLAN-OFF', planCode: 'basico-1800');

        // VITI Inicial no incluye `inventario`.
        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/peluqueria/productos', $this->headers($tenant))
            ->assertForbidden();
    }

    public function test_user_cannot_select_or_read_another_company(): void
    {
        $a = $this->tenant('TENANT-A');
        $b = $this->tenant('TENANT-B');

        $this->actingAs($a['user'])
            ->getJson('/api/v1/mi/apps/peluqueria/clientes', [
                'X-VITI-Empresa' => (string) $b['company']->id,
            ])
            ->assertForbidden();
    }

    public function test_current_aliases_are_enforced_without_creating_new_module_keys(): void
    {
        $tenant = $this->tenant(
            'ALIASES',
            role: 'empleado',
            permissions: ['inicio','ordenes','historial']
        );

        // Servicios y atenciones comparten temporalmente `ordenes`.
        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/peluqueria/servicios', $this->headers($tenant))
            ->assertOk();
        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/peluqueria/atenciones', $this->headers($tenant))
            ->assertOk();

        // Reportes comparte temporalmente `historial`.
        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/peluqueria/reportes', $this->headers($tenant))
            ->assertOk();

        // Inventario no está en permisos del usuario.
        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/peluqueria/productos', $this->headers($tenant))
            ->assertForbidden();
    }

    public function test_reports_do_not_bypass_permissions_of_other_modules(): void
    {
        $tenant = $this->tenant(
            'REPORT-SCOPE',
            role: 'empleado',
            permissions: ['historial']
        );

        $response = $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/peluqueria/reportes', $this->headers($tenant))
            ->assertOk();

        $response->assertJsonPath('data.resumen.ingresos', null)
            ->assertJsonPath('data.resumen.stock_bajo', null)
            ->assertJsonPath('data.resumen.clientes_nuevos', null)
            ->assertJsonPath('data.top_productos', [])
            ->assertJsonPath('data.top_personal', [])
            ->assertJsonPath('data.top_servicios', []);
    }

    public function test_dashboard_does_not_expose_payment_data_without_payment_permission(): void
    {
        $tenant = $this->tenant(
            'DASHBOARD-SCOPE',
            role: 'empleado',
            permissions: ['inicio']
        );

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/peluqueria/resumen', $this->headers($tenant))
            ->assertOk()
            ->assertJsonPath('data.ingresos_hoy', null)
            ->assertJsonPath('data.clientes', null)
            ->assertJsonPath('data.citas_hoy', null)
            ->assertJsonPath('data.en_atencion', null);
    }
}
