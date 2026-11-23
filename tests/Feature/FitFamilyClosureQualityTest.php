<?php

namespace Tests\Feature;

use App\Models\{Aplicacion,CatalogoAplicacion,Cliente,Empresa,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QA isolated: checks VITI's FitFamily integration against an in-memory
 * database. Does not create orders or modify stock in the live business.
 */
class FitFamilyClosureQualityTest extends TestCase
{
    use RefreshDatabase;

    private function setupFitFamily(): array
    {
        $empresa = Empresa::create([
            'codigo' => 'EMP-QA-FITFAMILY',
            'nombre_comercial' => 'FitFamily QA aislado',
            'estado' => 'activo',
        ]);
        $catalogo = CatalogoAplicacion::query()
            ->where('clave', 'fitfamily')->firstOrFail();
        Aplicacion::create([
            'empresa_id' => $empresa->id,
            'catalogo_aplicacion_id' => $catalogo->id,
            'nombre' => 'FitFamily QA',
            'slug' => 'fitfamily',
            'estado' => 'activo',
            'entorno' => 'produccion',
            'acceso_cliente' => true,
        ]);
        config()->set('fitfamily.empresa_id', $empresa->id);

        $admin = Usuario::create([
            'nombre' => 'Administrador QA',
            'usuario' => 'admin_fit_qa',
            'documento' => '994449911',
            'telefono' => '799449911',
            'password' => 'Prueba123456',
            'rol' => 'superadmin',
            'estado' => 'activo',
        ]);
        $caja = Usuario::create([
            'nombre' => 'Personal QA',
            'usuario' => 'personal_fit_qa',
            'documento' => '994449912',
            'telefono' => '799449912',
            'password' => 'Prueba123456',
            'rol' => 'cliente',
            'estado' => 'activo',
        ]);
        $categoriaId = DB::table('fitfamily_categorias')->insertGetId([
            'empresa_id' => $empresa->id,
            'nombre' => 'Batidos',
            'slug' => 'batidos',
            'activo' => true,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productoId = DB::table('fitfamily_productos')->insertGetId([
            'empresa_id' => $empresa->id,
            'categoria_id' => $categoriaId,
            'nombre' => 'Vainilla',
            'slug' => 'vainilla',
            'precio' => 24,
            'stock' => 3,
            'stock_minimo' => 1,
            'visible' => true,
            'disponible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('empresa', 'admin', 'caja', 'productoId');
    }

    public function test_only_an_administrator_can_read_or_change_the_catalog(): void
    {
        $s = $this->setupFitFamily();
        $url = '/api/v1/fitfamily/admin/productos';

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($s['caja'])->getJson($url)->assertForbidden();
        $this->actingAs($s['caja'])->postJson($url, [
            'categoria_id' => 1,
            'nombre' => 'No debe crearse',
            'precio' => 100,
            'stock' => 1,
        ])->assertForbidden();

        $this->actingAs($s['admin'])->getJson($url)->assertOk();
        $this->assertDatabaseMissing('fitfamily_productos', [
            'empresa_id' => $s['empresa']->id,
            'nombre' => 'No debe crearse',
        ]);
    }

    public function test_cancel_restores_product_stock_once_and_cannot_cancel_delivered_orders(): void
    {
        $s = $this->setupFitFamily();
        $cliente = Cliente::create([
            'nombre' => 'Cliente QA',
            'telefono' => '799440011',
            'estado' => 'activo',
        ]);

        $pedidoId = DB::table('fitfamily_pedidos')->insertGetId([
            'empresa_id' => $s['empresa']->id,
            'cliente_id' => $cliente->id,
            'numero' => 'FF-QA-CANCEL-001',
            'estado' => 'confirmado',
            'subtotal' => 48,
            'total' => 48,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fitfamily_pedido_detalles')->insert([
            'pedido_id' => $pedidoId,
            'producto_id' => $s['productoId'],
            'nombre_producto' => 'Vainilla',
            'cantidad' => 2,
            'precio_unitario' => 24,
            'subtotal' => 48,
        ]);
        $url = "/api/v1/fitfamily/admin/pedidos/{$pedidoId}/estado";

        $this->actingAs($s['caja'])->patchJson($url, [
            'estado' => 'cancelado',
        ])->assertForbidden();
        $this->assertEquals(3, DB::table('fitfamily_productos')
            ->where('id', $s['productoId'])->value('stock'));

        $this->actingAs($s['admin'])->patchJson($url, [
            'estado' => 'cancelado',
        ])->assertOk()->assertJsonPath('data.estado', 'cancelado');

        $this->assertEquals(5, DB::table('fitfamily_productos')
            ->where('id', $s['productoId'])->value('stock'));

        $this->patchJson($url, ['estado' => 'cancelado'])->assertUnprocessable();
        $this->assertEquals(5, DB::table('fitfamily_productos')
            ->where('id', $s['productoId'])->value('stock'));

        DB::table('fitfamily_pedidos')->where('id', $pedidoId)
            ->update(['estado' => 'entregado']);
        $this->patchJson($url, ['estado' => 'cancelado'])->assertUnprocessable();
        $this->assertEquals(5, DB::table('fitfamily_productos')
            ->where('id', $s['productoId'])->value('stock'));
    }
}
