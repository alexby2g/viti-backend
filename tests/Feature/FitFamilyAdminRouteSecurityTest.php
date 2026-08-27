<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FitFamilyAdminRouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_fitfamily_admin_routes_exist(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        foreach (['GET','POST'] as $method) {
            foreach (['categorias','productos'] as $resource) {
                $this->assertTrue(
                    $routes->contains(fn ($route) => in_array($method, $route->methods(), true) && $route->uri() === "api/v1/fitfamily/admin/{$resource}"),
                    "Missing FitFamily admin route: {$method} {$resource}"
                );
            }
        }
    }
}
