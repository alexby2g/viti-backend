<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fitfamily_categorias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $table->string('nombre', 120);
            $table->string('slug', 150);
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['aplicacion_id', 'slug']);
            $table->index(['empresa_id', 'activo']);
        });

        Schema::create('fitfamily_productos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('fitfamily_categorias')->nullOnDelete();
            $table->string('nombre', 180);
            $table->string('slug', 200);
            $table->text('descripcion')->nullable();
            $table->string('imagen_url', 1000)->nullable();
            $table->decimal('precio', 12, 2);
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('disponible')->default(true);
            $table->boolean('visible_catalogo')->default(true);
            $table->json('datos_nutricionales')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['aplicacion_id', 'slug']);
            $table->index(['empresa_id', 'disponible', 'visible_catalogo']);
            $table->index(['aplicacion_id', 'categoria_id']);
        });

        Schema::create('fitfamily_pedidos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->string('codigo', 40);
            $table->enum('estado', ['pendiente', 'aceptado', 'preparado', 'entregado', 'rechazado', 'cancelado'])->default('pendiente');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notas')->nullable();
            $table->string('direccion_entrega', 500)->nullable();
            $table->timestamp('aceptado_at')->nullable();
            $table->timestamp('preparado_at')->nullable();
            $table->timestamp('entregado_at')->nullable();
            $table->timestamp('rechazado_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['aplicacion_id', 'codigo']);
            $table->index(['empresa_id', 'estado']);
            $table->index(['aplicacion_id', 'cliente_id']);
        });

        Schema::create('fitfamily_pedido_detalles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pedido_id')->constrained('fitfamily_pedidos')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('fitfamily_productos')->restrictOnDelete();
            $table->string('producto_nombre', 180);
            $table->decimal('precio_unitario', 12, 2);
            $table->unsignedInteger('cantidad');
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
            $table->index('producto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fitfamily_pedido_detalles');
        Schema::dropIfExists('fitfamily_pedidos');
        Schema::dropIfExists('fitfamily_productos');
        Schema::dropIfExists('fitfamily_categorias');
    }
};
