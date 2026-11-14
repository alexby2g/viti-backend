<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla nueva y separada: ningún proyecto o avance existente se modifica.
        Schema::create('proyecto_tareas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();
            $table->foreignId('responsable_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('titulo', 200);
            $table->text('descripcion')->nullable();
            $table->text('criterios_aceptacion')->nullable();
            $table->string('area', 30)->default('general');
            $table->string('estado', 30)->default('pendiente');
            $table->string('prioridad', 20)->default('media');
            $table->date('fecha_limite')->nullable();
            $table->timestamp('completada_at')->nullable();
            $table->timestamps();
            $table->index(['proyecto_id', 'estado']);
            $table->index(['proyecto_id', 'prioridad']);
            $table->index(['responsable_id', 'estado']);
            $table->index(['proyecto_id', 'fecha_limite']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proyecto_tareas');
    }
};
