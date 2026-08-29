<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::table('catalogo_aplicaciones')->updateOrInsert(
            ['clave' => 'fitfamily'],
            [
                'nombre' => 'FitFamily',
                'descripcion' => 'Comercio y gestión de productos/alimentos',
                'tipo' => 'comercial',
                'ruta_base' => '/mi-apps/fitfamily/inicio',
                'activo' => true,
                'solicitable' => true,
                'orden' => 60,
            ]
        );

        Schema::create('fitfamily_categorias', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $t->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $t->string('nombre', 120);
            $t->string('slug', 140);
            $t->text('descripcion')->nullable();
            $t->text('imagen_url')->nullable();
            $t->boolean('activo')->default(true);
            $t->integer('orden')->default(0);
            $t->timestamps();
            $t->unique(['empresa_id', 'aplicacion_id', 'slug']);
            $t->index(['empresa_id', 'aplicacion_id', 'activo']);
        });

        Schema::create('fitfamily_productos', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $t->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $t->foreignId('categoria_id')->constrained('fitfamily_categorias')->restrictOnDelete();
            $t->string('nombre', 180);
            $t->string('slug', 200);
            $t->text('descripcion')->nullable();
            $t->decimal('precio', 12, 2)->default(0);
            $t->integer('stock')->default(0);
            $t->integer('stock_minimo')->default(0);
            $t->text('imagen_url')->nullable();
            $t->boolean('disponible')->default(true);
            $t->boolean('visible')->default(true);
            $t->timestamps();
            $t->unique(['empresa_id', 'aplicacion_id', 'slug']);
            $t->index(['empresa_id', 'aplicacion_id', 'visible', 'disponible']);
        });

        Schema::create('fitfamily_carritos', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $t->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $t->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $t->string('session_token', 100)->nullable();
            $t->string('estado', 20)->default('activo');
            $t->timestamps();
            $t->index(['empresa_id', 'aplicacion_id', 'usuario_id', 'estado']);
            $t->unique(['empresa_id', 'aplicacion_id', 'session_token']);
        });

        Schema::create('fitfamily_carrito_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('carrito_id')->constrained('fitfamily_carritos')->cascadeOnDelete();
            $t->foreignId('producto_id')->constrained('fitfamily_productos')->restrictOnDelete();
            $t->integer('cantidad');
            $t->decimal('precio_unitario', 12, 2);
            $t->unique(['carrito_id', 'producto_id']);
        });

        Schema::create('fitfamily_pedidos', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $t->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $t->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $t->string('numero', 40)->unique();
            $t->string('estado', 30)->default('pendiente');
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('total', 12, 2)->default(0);
            $t->text('notas')->nullable();
            $t->timestamps();
            $t->index(['empresa_id', 'aplicacion_id', 'estado']);
        });

        Schema::create('fitfamily_pedido_detalles', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('pedido_id')->constrained('fitfamily_pedidos')->cascadeOnDelete();
            $t->foreignId('producto_id')->constrained('fitfamily_productos')->restrictOnDelete();
            $t->string('nombre_producto', 180);
            $t->integer('cantidad');
            $t->decimal('precio_unitario', 12, 2);
            $t->decimal('subtotal', 12, 2);
        });

        Schema::create('fitfamily_pagos', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('pedido_id')->constrained('fitfamily_pedidos')->cascadeOnDelete();
            $t->string('metodo_pago', 40);
            $t->string('estado', 30)->default('pendiente');
            $t->decimal('monto', 12, 2);
            $t->string('referencia', 160)->nullable();
            $t->timestamps();
            $t->index(['pedido_id', 'estado']);
        });

        Schema::create('fitfamily_configuracion', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $t->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $t->string('clave', 120);
            $t->text('valor')->nullable();
            $t->timestamps();
            $t->unique(['empresa_id', 'aplicacion_id', 'clave']);
        });
    }

    public function down(): void
    {
        foreach ([
            'fitfamily_configuracion',
            'fitfamily_pagos',
            'fitfamily_pedido_detalles',
            'fitfamily_pedidos',
            'fitfamily_carrito_items',
            'fitfamily_carritos',
            'fitfamily_productos',
            'fitfamily_categorias',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
