<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peluqueria_servicios', function (Blueprint $table): void {
            $table->string('tipo', 30)->default('servicio')->after('categoria')->index();
        });

        Schema::create('peluqueria_combo_servicios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('combo_id')->constrained('peluqueria_servicios')->cascadeOnDelete();
            $table->foreignId('servicio_id')->constrained('peluqueria_servicios')->cascadeOnDelete();
            $table->unsignedInteger('cantidad')->default(1);
            $table->timestamps();
            $table->unique(['combo_id','servicio_id']);
        });

        Schema::create('peluqueria_productos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 180);
            $table->string('categoria', 100)->nullable();
            $table->string('tipo', 30)->default('mixto');
            $table->string('unidad', 30)->default('unidad');
            $table->decimal('stock', 12, 2)->default(0);
            $table->decimal('stock_minimo', 12, 2)->default(0);
            $table->decimal('costo', 12, 2)->nullable();
            $table->decimal('precio_venta', 12, 2)->nullable();
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['empresa_id','activo']);
            $table->index(['empresa_id','nombre']);
        });

        Schema::create('peluqueria_producto_movimientos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('peluqueria_productos')->cascadeOnDelete();
            $table->foreignId('atencion_id')->nullable()->constrained('peluqueria_atenciones')->nullOnDelete();
            $table->string('tipo', 30);
            $table->decimal('cantidad', 12, 2);
            $table->decimal('costo_unitario', 12, 2)->nullable();
            $table->decimal('precio_unitario', 12, 2)->nullable();
            $table->string('motivo', 255)->nullable();
            $table->dateTime('registrado_at');
            $table->timestamps();
            $table->index(['empresa_id','registrado_at']);
            $table->index(['producto_id','tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peluqueria_producto_movimientos');
        Schema::dropIfExists('peluqueria_productos');
        Schema::dropIfExists('peluqueria_combo_servicios');
        Schema::table('peluqueria_servicios', function (Blueprint $table): void {
            $table->dropColumn('tipo');
        });
    }
};
