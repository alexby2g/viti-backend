<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FitFamilyFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fitfamily_foundation_tables_exist_with_tenant_and_application_columns(): void
    {
        foreach ([
            'fitfamily_categorias',
            'fitfamily_productos',
            'fitfamily_pedidos',
            'fitfamily_pedido_detalles',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        $this->assertTrue(Schema::hasColumns('fitfamily_categorias', ['empresa_id', 'aplicacion_id', 'slug']));
        $this->assertTrue(Schema::hasColumns('fitfamily_productos', ['empresa_id', 'aplicacion_id', 'categoria_id', 'precio', 'stock', 'visible_catalogo']));
        $this->assertTrue(Schema::hasColumns('fitfamily_pedidos', ['empresa_id', 'aplicacion_id', 'cliente_id', 'codigo', 'estado', 'total']));
        $this->assertTrue(Schema::hasColumns('fitfamily_pedido_detalles', ['pedido_id', 'producto_id', 'producto_nombre', 'precio_unitario', 'cantidad', 'subtotal']));
    }
}
