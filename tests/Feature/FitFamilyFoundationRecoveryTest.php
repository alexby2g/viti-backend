<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FitFamilyFoundationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_fitfamily_foundation_is_tenant_and_application_scoped(): void
    {
        $this->assertTrue(Schema::hasTable('fitfamily_categorias'));
        $this->assertTrue(Schema::hasTable('fitfamily_productos'));
        $this->assertTrue(Schema::hasTable('fitfamily_pedidos'));
        $this->assertTrue(Schema::hasTable('fitfamily_pedido_detalles'));

        foreach (['fitfamily_categorias', 'fitfamily_productos', 'fitfamily_pedidos'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'empresa_id'));
            $this->assertTrue(Schema::hasColumn($table, 'aplicacion_id'));
        }

        $this->assertTrue(Schema::hasColumns('fitfamily_productos', [
            'categoria_id', 'precio', 'stock', 'disponible', 'visible_catalogo', 'datos_nutricionales',
        ]));
        $this->assertTrue(Schema::hasColumns('fitfamily_pedidos', [
            'cliente_id', 'codigo', 'estado', 'subtotal', 'total', 'direccion_entrega',
        ]));
        $this->assertTrue(Schema::hasColumns('fitfamily_pedido_detalles', [
            'pedido_id', 'producto_id', 'producto_nombre', 'precio_unitario', 'cantidad', 'subtotal',
        ]));
    }
}
