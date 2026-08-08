<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('peluqueria_clientes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 180);
            $table->string('telefono', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->string('sexo', 30)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->text('observaciones')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['empresa_id', 'nombre']);
            $table->index(['empresa_id', 'telefono']);
        });

        Schema::create('peluqueria_servicios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 160);
            $table->string('categoria', 100)->nullable();
            $table->unsignedInteger('duracion_minutos')->default(30);
            $table->decimal('precio', 12, 2)->default(0);
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['empresa_id', 'activo']);
        });

        Schema::create('peluqueria_personal', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 180);
            $table->string('telefono', 30)->nullable();
            $table->string('especialidad', 160)->nullable();
            $table->time('horario_inicio')->nullable();
            $table->time('horario_fin')->nullable();
            $table->decimal('porcentaje_comision', 5, 2)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['empresa_id', 'activo']);
        });

        Schema::create('peluqueria_citas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('peluqueria_clientes')->cascadeOnDelete();
            $table->foreignId('servicio_id')->constrained('peluqueria_servicios')->restrictOnDelete();
            $table->foreignId('personal_id')->nullable()->constrained('peluqueria_personal')->nullOnDelete();
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin')->nullable();
            $table->string('estado', 40)->default('pendiente');
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'fecha', 'estado']);
            $table->index(['personal_id', 'fecha', 'hora_inicio']);
        });

        Schema::create('peluqueria_atenciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('cita_id')->nullable()->constrained('peluqueria_citas')->nullOnDelete();
            $table->foreignId('cliente_id')->constrained('peluqueria_clientes')->restrictOnDelete();
            $table->foreignId('servicio_id')->constrained('peluqueria_servicios')->restrictOnDelete();
            $table->foreignId('personal_id')->nullable()->constrained('peluqueria_personal')->nullOnDelete();
            $table->string('estado', 40)->default('en_atencion');
            $table->dateTime('iniciada_at')->nullable();
            $table->dateTime('finalizada_at')->nullable();
            $table->decimal('precio_servicio', 12, 2)->default(0);
            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'estado']);
            $table->index(['empresa_id', 'finalizada_at']);
        });

        Schema::create('peluqueria_pagos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('atencion_id')->constrained('peluqueria_atenciones')->cascadeOnDelete();
            $table->string('metodo', 40);
            $table->decimal('monto', 12, 2);
            $table->string('referencia', 120)->nullable();
            $table->dateTime('pagado_at');
            $table->timestamps();
            $table->index(['empresa_id', 'pagado_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peluqueria_pagos');
        Schema::dropIfExists('peluqueria_atenciones');
        Schema::dropIfExists('peluqueria_citas');
        Schema::dropIfExists('peluqueria_personal');
        Schema::dropIfExists('peluqueria_servicios');
        Schema::dropIfExists('peluqueria_clientes');
    }
};
