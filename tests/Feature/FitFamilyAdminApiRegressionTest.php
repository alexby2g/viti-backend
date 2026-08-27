<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FitFamilyAdminApiRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fitfamily_admin_routes_are_registered(): void
    {
        foreach ([
            'GET|api/v1/fitfamily/admin/categorias',
            'POST|api/v1/fitfamily/admin/categorias',
            'GET|api/v1/fitfamily/admin/productos',
            'POST|api/v1/fitfamily/admin/productos',
        ] as $signature) {
            [$method, $uri] = explode('|', $signature, 2);
            $found = collect(Route::getRoutes()->getRoutes())
                ->contains(fn ($route) => in_array($method, $route->methods(), true) && $route->uri() === $uri);
            $this->assertTrue($found, "Missing route: {$signature}");
        }
    }
}
