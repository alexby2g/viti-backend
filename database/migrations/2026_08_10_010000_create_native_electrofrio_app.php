<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electrofrio_clientes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 180);
            $table->string('telefono', 30)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('referencia', 255)->nullable();
            $table->text('observaciones')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['empresa_id', 'nombre']);
            $table->index(['empresa_id', 'telefono']);
        });

        Schema::create('electrofrio_equipos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('electrofrio_clientes')->cascadeOnDelete();
            $table->string('tipo', 120);
            $table->string('marca', 120)->nullable();
            $table->string('modelo', 120)->nullable();
            $table->string('serie', 120)->nullable();
            $table->string('capacidad', 100)->nullable();
            $table->string('ubicacion', 255)->nullable();
            $table->text('observaciones')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['empresa_id', 'cliente_id']);
            $table->index(['empresa_id', 'tipo']);
        });

        Schema::create('electrofrio_tecnicos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 180);
            $table->string('telefono', 30)->nullable();
            $table->string('especialidad', 160)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['empresa_id', 'activo']);
        });

        Schema::create('electrofrio_materiales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 180);
            $table->string('unidad', 40)->default('unidad');
            $table->decimal('stock', 12, 2)->default(0);
            $table->decimal('stock_minimo', 12, 2)->default(0);
            $table->decimal('costo_unitario', 12, 2)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['empresa_id', 'activo']);
            $table->unique(['empresa_id', 'nombre']);
        });

        Schema::create('electrofrio_ordenes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('codigo', 40)->unique();
            $table->foreignId('cliente_id')->constrained('electrofrio_clientes')->restrictOnDelete();
            $table->foreignId('equipo_id')->nullable()->constrained('electrofrio_equipos')->nullOnDelete();
            $table->foreignId('tecnico_id')->nullable()->constrained('electrofrio_tecnicos')->nullOnDelete();
            $table->date('fecha_cita');
            $table->time('hora_cita')->nullable();
            $table->string('direccion_servicio', 255);
            $table->string('referencia_ubicacion', 255)->nullable();
            $table->text('problema_reportado');
            $table->string('prioridad', 20)->default('normal');
            $table->string('etapa', 30)->default('cita');
            $table->string('decision_cliente', 20)->default('pendiente');
            $table->text('diagnostico')->nullable();
            $table->text('propuesta')->nullable();
            $table->text('motivo_rechazo')->nullable();
            $table->text('trabajo_realizado')->nullable();
            $table->text('recomendaciones')->nullable();
            $table->decimal('costo_mano_obra', 12, 2)->default(0);
            $table->decimal('costo_materiales', 12, 2)->default(0);
            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->unsignedInteger('garantia_dias')->default(0);
            $table->date('garantia_inicio')->nullable();
            $table->date('garantia_fin')->nullable();
            $table->text('condiciones_garantia')->nullable();
            $table->timestamp('decision_at')->nullable();
            $table->timestamp('finalizada_at')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'fecha_cita', 'etapa']);
            $table->index(['empresa_id', 'decision_cliente']);
            $table->index(['empresa_id', 'garantia_fin']);
        });

        Schema::create('electrofrio_orden_material', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('orden_id')->constrained('electrofrio_ordenes')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('electrofrio_materiales')->restrictOnDelete();
            $table->decimal('cantidad', 12, 2);
            $table->decimal('costo_unitario', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
            $table->unique(['orden_id', 'material_id']);
            $table->index(['empresa_id', 'orden_id']);
        });

        Schema::create('electrofrio_pagos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('orden_id')->constrained('electrofrio_ordenes')->cascadeOnDelete();
            $table->decimal('monto', 12, 2);
            $table->string('metodo', 40);
            $table->string('referencia', 120)->nullable();
            $table->string('estado', 30)->default('pagado');
            $table->timestamp('pagado_at');
            $table->timestamps();
            $table->index(['empresa_id', 'pagado_at']);
        });

        DB::transaction(function (): void {
            $now = now();
            DB::table('catalogo_aplicaciones')->where('clave', 'electrofrio')->update([
                'descripcion' => 'Aplicación VITI para citas, diagnóstico, órdenes de servicio, equipos, materiales, pagos, garantías e historial de Electrofrío.',
                'ruta_base' => '/apps/electrofrio/inicio',
                'activo' => true,
                'solicitable' => false,
                'updated_at' => $now,
            ]);

            DB::table('aplicaciones')->where('slug', 'electrofrio-viti')->update([
                'version' => 'VITI 1.0',
                'tecnologias' => 'VITI Core · Laravel · Quasar',
                'url' => null,
                'url_administracion' => null,
                'repositorio_url' => null,
                'proveedor_hosting' => 'VITI · Vercel + Render',
                'notas' => 'Aplicación nativa de VITI. Sin WhatsApp y con datos operativos aislados que comienzan vacíos.',
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electrofrio_pagos');
        Schema::dropIfExists('electrofrio_orden_material');
        Schema::dropIfExists('electrofrio_ordenes');
        Schema::dropIfExists('electrofrio_materiales');
        Schema::dropIfExists('electrofrio_tecnicos');
        Schema::dropIfExists('electrofrio_equipos');
        Schema::dropIfExists('electrofrio_clientes');
    }
};
