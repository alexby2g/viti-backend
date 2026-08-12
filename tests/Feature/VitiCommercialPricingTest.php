<?php

namespace Tests\Feature;

use App\Models\PlanViti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VitiCommercialPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_plans_have_expected_implementation_monthly_and_annual_prices(): void
    {
        $initial = PlanViti::query()->where('codigo','basico-1800')->firstOrFail();
        $professional = PlanViti::query()->where('codigo','profesional-1950')->firstOrFail();
        $enterprise = PlanViti::query()->where('codigo','empresa-2500')->firstOrFail();
        $custom = PlanViti::query()->where('codigo','personalizado')->firstOrFail();

        $this->assertSame('VITI Inicial', $initial->nombre);
        $this->assertSame('1800.00', $initial->precio_proyecto);
        $this->assertSame('89.00', $initial->precio_mensual);
        $this->assertSame('890.00', $initial->precio_anual);
        $this->assertSame(3, $initial->max_usuarios);
        $this->assertSame(1, $initial->max_aplicaciones);

        $this->assertSame('VITI Profesional', $professional->nombre);
        $this->assertSame('2400.00', $professional->precio_proyecto);
        $this->assertSame('129.00', $professional->precio_mensual);
        $this->assertSame('1290.00', $professional->precio_anual);
        $this->assertSame(6, $professional->max_usuarios);

        $this->assertSame('VITI Empresa', $enterprise->nombre);
        $this->assertSame('3200.00', $enterprise->precio_proyecto);
        $this->assertSame('189.00', $enterprise->precio_mensual);
        $this->assertSame('1890.00', $enterprise->precio_anual);
        $this->assertSame(15, $enterprise->max_usuarios);
        $this->assertSame(3, $enterprise->max_aplicaciones);

        $this->assertSame('Cotización personalizada', $custom->nombre);
        $this->assertNull($custom->precio_proyecto);
        $this->assertNull($custom->precio_mensual);
        $this->assertNull($custom->precio_anual);
        $this->assertTrue($custom->activo);
    }

    public function test_annual_prices_equal_ten_months_for_standard_plans(): void
    {
        foreach (PlanViti::query()->whereIn('codigo',['basico-1800','profesional-1950','empresa-2500'])->get() as $plan) {
            $this->assertEquals((float)$plan->precio_mensual * 10, (float)$plan->precio_anual);
            $this->assertEquals((float)$plan->precio_mensual * 2, ((float)$plan->precio_mensual * 12) - (float)$plan->precio_anual);
        }
    }
}
