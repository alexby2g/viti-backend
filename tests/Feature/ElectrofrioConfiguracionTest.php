<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioConfiguracionTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_owner_reads_defaults_and_can_personalize_the_system(): void
    {
        $tenant = $this->createTenant('AIR-CONFIG');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/configuracion', $headers)
            ->assertOk()
            ->assertJsonPath('data.nombre_sistema', 'Sistema de Gestión de Servicios de Aire Acondicionado')
            ->assertJsonPath('data.personalizada', false);

        $this->actingAs($tenant['user'])
            ->putJson('/api/v1/mi/apps/electrofrio/configuracion', [
                'nombre_sistema' => 'Clima Norte - Gestión Técnica',
                'nombre_corto' => 'Clima Norte',
                'logo_url' => 'https://example.com/logo.png',
                'telefono' => '70000000',
                'correo' => 'servicio@climanorte.test',
                'direccion' => 'Santa Cruz de la Sierra',
                'color_primario' => '#123456',
                'color_secundario' => '#32AABB',
                'moneda' => 'BOB',
                'garantia_dias_default' => 30,
                'tipos_servicio' => ['Diagnóstico', 'Instalación', 'Limpieza'],
                'tipos_equipo' => ['Split', 'Cassette'],
                'metodos_pago' => ['Efectivo', 'QR'],
            ], $headers)
            ->assertOk()
            ->assertJsonPath('data.nombre_corto', 'Clima Norte')
            ->assertJsonPath('data.color_primario', '#123456')
            ->assertJsonPath('data.metodos_pago.1', 'qr')
            ->assertJsonPath('data.personalizada', true);

        $this->assertDatabaseHas('electrofrio_configuraciones', [
            'empresa_id' => $tenant['company']->id,
            'nombre_sistema' => 'Clima Norte - Gestión Técnica',
            'garantia_dias_default' => 30,
            'actualizado_por' => $tenant['user']->id,
        ]);
        $this->assertDatabaseHas('auditoria', [
            'empresa_id' => $tenant['company']->id,
            'accion' => 'aires_acondicionados_configuracion_actualizada',
        ]);
    }

    public function test_employee_can_read_but_cannot_modify_business_personalization(): void
    {
        $tenant = $this->createTenant('AIR-CONFIG-EMP', 'empleado', ['inicio']);
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/configuracion', $headers)
            ->assertOk();

        $this->actingAs($tenant['user'])
            ->putJson('/api/v1/mi/apps/electrofrio/configuracion', [
                'nombre_sistema' => 'No permitido',
                'nombre_corto' => 'No permitido',
                'color_primario' => '#123456',
                'color_secundario' => '#654321',
                'moneda' => 'BOB',
                'garantia_dias_default' => 0,
                'tipos_servicio' => ['Diagnóstico'],
                'tipos_equipo' => ['Split'],
                'metodos_pago' => ['efectivo'],
            ], $headers)
            ->assertForbidden();
    }
}
