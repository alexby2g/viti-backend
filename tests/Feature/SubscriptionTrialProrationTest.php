<?php

namespace Tests\Feature;

use App\Models\{Aplicacion,CatalogoAplicacion,Cliente,Empresa,PlanViti,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTrialProrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_professional_monthly_subscription_has_14_day_trial_and_prorated_first_month(): void
    {
        $admin = Usuario::create([
            'nombre'=>'Admin Suscripciones',
            'usuario'=>'admin_suscripciones',
            'documento'=>'99881122',
            'telefono'=>'70009988',
            'password'=>'Prueba1234',
            'rol'=>'superadmin',
            'estado'=>'activo',
        ]);
        $client = Cliente::create([
            'nombre'=>'Laura Prueba',
            'telefono'=>'73998811',
            'estado'=>'informacion_recibida',
        ]);
        $plan = PlanViti::query()->where('codigo','profesional-1950')->firstOrFail();
        $company = Empresa::create([
            'cliente_id'=>$client->id,
            'plan_viti_id'=>$plan->id,
            'codigo'=>'EMP-TRIAL-TEST',
            'nombre_comercial'=>'Soporte Vital Test',
            'estado'=>'activo',
        ]);
        $catalog = CatalogoAplicacion::create([
            'clave'=>'servicio-tecnico-test',
            'nombre'=>'Servicio Técnico Test',
            'descripcion'=>'Aplicación para probar suscripciones.',
            'icono'=>'computer',
            'tipo'=>'web',
            'ruta_base'=>'/apps/servicio-tecnico',
            'activo'=>true,
            'solicitable'=>false,
            'orden'=>99,
        ]);
        $app = Aplicacion::create([
            'empresa_id'=>$company->id,
            'catalogo_aplicacion_id'=>$catalog->id,
            'nombre'=>'Soporte Vital Test',
            'slug'=>'soporte-vital-test',
            'version'=>'0.5.0',
            'tipo'=>'web',
            'entorno'=>'beta',
            'estado'=>'en_pruebas',
            'acceso_cliente'=>false,
        ]);

        $response = $this->actingAs($admin)->putJson("/api/v1/aplicaciones/{$app->id}/suscripcion", [
            'plan'=>'Plan Profesional',
            'monto'=>50,
            'frecuencia'=>'mensual',
            'fecha_inicio'=>'2026-08-24',
            'dias_gracia'=>7,
            'estado'=>'activa',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.prueba_hasta','2026-09-06')
            ->assertJsonPath('data.primer_cobro_desde','2026-09-07')
            ->assertJsonPath('data.primer_cobro_hasta','2026-09-30')
            ->assertJsonPath('data.primer_cobro_monto',40)
            ->assertJsonPath('data.monto',50);

        $this->assertDatabaseHas('suscripciones', [
            'aplicacion_id'=>$app->id,
            'empresa_id'=>$company->id,
            'plan'=>'Plan Profesional',
            'monto'=>50,
            'prueba_hasta'=>'2026-09-06',
            'primer_cobro_desde'=>'2026-09-07',
            'primer_cobro_hasta'=>'2026-09-30',
            'primer_cobro_monto'=>40,
        ]);
    }

    public function test_commercial_saas_routes_are_connected(): void
    {
        $admin = Usuario::create([
            'nombre'=>'Admin SaaS',
            'usuario'=>'admin_saas_routes',
            'documento'=>'99117733',
            'telefono'=>'70007733',
            'password'=>'Prueba1234',
            'rol'=>'superadmin',
            'estado'=>'activo',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/saas/resumen')
            ->assertOk()
            ->assertJsonStructure(['data'=>['resumen','atencion','catalogo','planes']]);

        $this->actingAs($admin)
            ->getJson('/api/v1/pagos')
            ->assertOk()
            ->assertJsonStructure(['data'=>['resumen','proyectos','suscripciones']]);
    }
}
