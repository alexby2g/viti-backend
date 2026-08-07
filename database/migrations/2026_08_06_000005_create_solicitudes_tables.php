<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('solicitudes_sistema', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('cuestionario_id')->constrained('cuestionarios')->restrictOnDelete();
            $table->foreignId('asignado_a')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('codigo', 30)->unique();
            $table->string('public_token', 80)->unique();
            $table->boolean('publico_habilitado')->default(true);
            $table->string('titulo', 200);
            $table->text('resumen')->nullable();
            $table->string('estado', 40)->default('borrador')->index();
            $table->string('prioridad', 20)->default('normal')->index();
            $table->date('fecha_limite_deseada')->nullable();
            $table->decimal('presupuesto_estimado', 12, 2)->nullable();
            $table->timestamp('enviado_at')->nullable();
            $table->timestamp('aprobado_at')->nullable();
            $table->boolean('declaracion_aceptada')->default(false);
            $table->string('declaracion_nombre', 180)->nullable();
            $table->date('declaracion_fecha')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('solicitud_respuestas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes_sistema')->cascadeOnDelete();
            $table->foreignId('pregunta_id')->constrained('cuestionario_preguntas')->restrictOnDelete();
            $table->longText('respuesta_texto')->nullable();
            $table->json('respuesta_json')->nullable();
            $table->timestamps();
            $table->unique(['solicitud_id', 'pregunta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_respuestas');
        Schema::dropIfExists('solicitudes_sistema');
    }
};
