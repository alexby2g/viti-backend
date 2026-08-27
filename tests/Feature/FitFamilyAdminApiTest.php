<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FitFamilyAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_fitfamily_admin_routes_are_registered_without_opening_public_access(): void
    {
        $routes = collect(app('router')->getRoutes())->map(fn ($route) => [
            'uri' => $route->uri(),
            'methods' => $route->methods(),
            'middleware' => $route->gatherMiddleware(),
        ]);

        $expected = [
            'api/v1/fitfamily/admin/categorias',
            'api/v1/fitfamily/admin/productos',
        ];

        foreach ($expected as $uri) {
            $route = $routes->firstWhere('uri', $uri);
            $this->assertNotNull($route, "Missing route: {$uri}");
            $this->assertContains('auth:sanctum', $route['middleware']);
            $this->assertContains('tenant', $route['middleware']);
        }
    }

    public function test_fitfamily_foundation_tables_remain_present(): void
    {
        foreach ([
            'fitfamily_categorias',
            'fitfamily_productos',
            'fitfamily_pedidos',
            'fitfamily_pedido_detalles',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }
}
