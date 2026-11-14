<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aditiva: se conserva intacto el historial de proyectos, archivos y pagos.
        Schema::create('proyecto_entregas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proyecto_id')->unique()->constrained('proyectos')->cascadeOnDelete();
            $table->string('titulo', 180)->default('Entrega del proyecto');
            $table->text('instrucciones')->nullable();
            $table->json('archivo_ids')->nullable();
            $table->string('estado', 20)->default('borrador')->index();
            $table->foreignId('publicado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('publicado_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proyecto_entregas');
    }
};
