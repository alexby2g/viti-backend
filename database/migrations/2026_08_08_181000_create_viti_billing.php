<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->decimal('precio_acordado', 12, 2)->nullable();
            $table->decimal('anticipo_monto', 12, 2)->nullable();
            $table->decimal('saldo_monto', 12, 2)->nullable();
            $table->string('estado_pago', 40)->default('sin_acuerdo');
        });

        Schema::create('proyecto_pagos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->string('tipo', 30); // anticipo | saldo_final | otro
            $table->decimal('monto', 12, 2);
            $table->string('metodo', 30)->default('qr');
            $table->date('fecha_pago');
            $table->string('referencia', 180)->nullable();
            $table->string('comprobante_path')->nullable();
            $table->text('observaciones')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();
            $table->index(['proyecto_id', 'tipo']);
        });

        Schema::create('suscripciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aplicacion_id')->unique()->constrained('aplicaciones')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('plan', 80)->default('VITI Soporte');
            $table->decimal('monto', 12, 2)->default(70);
            $table->string('frecuencia', 20)->default('mensual'); // mensual | anual
            $table->string('moneda', 3)->default('BOB');
            $table->date('fecha_inicio');
            $table->date('fecha_vencimiento');
            $table->unsignedSmallInteger('dias_gracia')->default(7);
            $table->string('estado', 30)->default('activa'); // activa | gracia | suspendida | cancelada
            $table->timestamps();
            $table->index(['empresa_id', 'estado']);
        });

        Schema::create('suscripcion_pagos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->decimal('monto', 12, 2);
            $table->string('metodo', 30)->default('qr');
            $table->date('fecha_pago');
            $table->string('referencia', 180)->nullable();
            $table->string('comprobante_path')->nullable();
            $table->text('observaciones')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();
        });

        Schema::create('configuraciones_pago', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 100)->default('QR principal VITI');
            $table->string('banco', 120)->nullable();
            $table->string('titular', 180)->nullable();
            $table->string('moneda', 3)->default('BOB');
            $table->string('qr_path')->nullable();
            $table->boolean('activo')->default(true);
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });

        DB::table('configuraciones_pago')->insert([
            'nombre' => 'QR principal VITI',
            'banco' => 'Banco Ganadero',
            'titular' => 'Guzman Ribera Alexander',
            'moneda' => 'BOB',
            'qr_path' => '/pagos/qr-banco-ganadero.png',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones_pago');
        Schema::dropIfExists('suscripcion_pagos');
        Schema::dropIfExists('suscripciones');
        Schema::dropIfExists('proyecto_pagos');

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropColumn(['precio_acordado', 'anticipo_monto', 'saldo_monto', 'estado_pago']);
        });
    }
};
