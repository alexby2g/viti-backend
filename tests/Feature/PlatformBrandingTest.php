<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_branding_is_public_and_keeps_viti_meaning_by_default(): void
    {
        $this->getJson('/api/v1/branding')
            ->assertOk()
            ->assertJsonPath('data.product_name', 'VITI')
            ->assertJsonPath('data.product_meaning', 'Visión Integral, Tecnología e Innovación')
            ->assertJsonPath('data.guide_enabled', true)
            ->assertJsonPath('data.guide_position', 'right-center');
    }

    public function test_only_superadmin_can_change_platform_branding(): void
    {
        $admin = Usuario::create([
            'nombre' => 'Administrador',
            'usuario' => 'branding_admin',
            'documento' => '99000101',
            'telefono' => '70000101',
            'password' => 'PruebaSegura123',
            'rol' => 'administrador',
            'estado' => 'activo',
        ]);
        $superadmin = Usuario::create([
            'nombre' => 'Superadmin',
            'usuario' => 'branding_superadmin',
            'documento' => '99000102',
            'telefono' => '70000102',
            'password' => 'PruebaSegura123',
            'rol' => 'superadmin',
            'estado' => 'activo',
        ]);

        $payload = [
            'studio_name' => 'AGR Studio',
            'product_name' => 'VITI',
            'product_meaning' => 'Visión Integral, Tecnología e Innovación',
            'tagline' => 'Gestión digital para microempresas de servicios',
            'primary_color' => '#6758D9',
            'secondary_color' => '#43A047',
            'accent_color' => '#FB8C00',
            'dark_color' => '#071C3B',
            'drawer_color' => '#09090B',
            'guide_enabled' => true,
            'guide_position' => 'right-bottom',
        ];

        $this->actingAs($admin)->putJson('/api/v1/branding', $payload)->assertForbidden();

        $this->actingAs($superadmin)->putJson('/api/v1/branding', $payload)
            ->assertOk()
            ->assertJsonPath('data.tagline', 'Gestión digital para microempresas de servicios')
            ->assertJsonPath('data.primary_color', '#6758D9')
            ->assertJsonPath('data.guide_position', 'right-bottom');

        $this->assertDatabaseHas('configuraciones_plataforma', [
            'product_name' => 'VITI',
            'primary_color' => '#6758D9',
            'guide_position' => 'right-bottom',
        ]);
    }
}
