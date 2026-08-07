<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('proyectos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('solicitud_id')->nullable()->constrained('solicitudes_sistema')->nullOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('responsable_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 200);
            $table->text('descripcion')->nullable();
            $table->string('fase', 40)->default('analisis')->index();
            $table->string('estado', 30)->default('activo')->index();
            $table->unsignedTinyInteger('progreso')->default(0);
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_beta')->nullable();
            $table->date('fecha_entrega')->nullable();
            $table->string('repositorio_url')->nullable();
            $table->string('produccion_url')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('proyecto_avances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('fase', 40);
            $table->string('titulo', 200);
            $table->text('descripcion')->nullable();
            $table->unsignedTinyInteger('progreso')->nullable();
            $table->boolean('visible_cliente')->default(false);
            $table->timestamps();
        });

        Schema::create('aplicaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('proyecto_id')->nullable()->constrained('proyectos')->nullOnDelete();
            $table->string('nombre', 200);
            $table->string('slug', 180)->unique();
            $table->string('version', 40)->nullable();
            $table->string('entorno', 30)->default('beta')->index();
            $table->string('estado', 30)->default('en_pruebas')->index();
            $table->string('url')->nullable();
            $table->string('url_administracion')->nullable();
            $table->text('notas')->nullable();
            $table->timestamp('publicado_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('mantenimientos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('asignado_a')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('codigo', 30)->unique();
            $table->string('titulo', 200);
            $table->text('descripcion');
            $table->string('tipo', 30)->default('soporte');
            $table->string('prioridad', 20)->default('normal')->index();
            $table->string('estado', 30)->default('abierto')->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mantenimientos');
        Schema::dropIfExists('aplicaciones');
        Schema::dropIfExists('proyecto_avances');
        Schema::dropIfExists('proyectos');
    }
};
