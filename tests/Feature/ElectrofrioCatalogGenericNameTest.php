<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ElectrofrioCatalogGenericNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_air_conditioning_catalog_uses_generic_product_name(): void
    {
        $catalog = DB::table('catalogo_aplicaciones')->where('clave', 'electrofrio')->first();

        $this->assertNotNull($catalog);
        $this->assertSame('Sistema de Gestión de Servicios de Aire Acondicionado', $catalog->nombre);
        $this->assertStringNotContainsString('Electrofrío', $catalog->descripcion);
    }
}
