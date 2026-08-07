<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cuestionarios', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 180);
            $table->string('version', 30)->default('1.0');
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('cuestionario_secciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cuestionario_id')->constrained('cuestionarios')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->string('titulo', 180);
            $table->text('descripcion')->nullable();
            $table->unsignedSmallInteger('orden');
            $table->timestamps();
            $table->unique(['cuestionario_id', 'numero']);
        });

        Schema::create('cuestionario_preguntas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seccion_id')->constrained('cuestionario_secciones')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->text('pregunta');
            $table->string('tipo', 30)->default('texto');
            $table->json('opciones')->nullable();
            $table->text('ayuda')->nullable();
            $table->boolean('obligatoria')->default(false);
            $table->unsignedSmallInteger('orden');
            $table->timestamps();
            $table->unique('numero');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuestionario_preguntas');
        Schema::dropIfExists('cuestionario_secciones');
        Schema::dropIfExists('cuestionarios');
    }
};
