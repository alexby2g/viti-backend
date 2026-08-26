<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('solicitudes_acceso_viti', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre',180);
            $table->string('telefono',30);
            $table->string('whatsapp',30)->nullable();
            $table->string('negocio',180);
            $table->string('actividad',180)->nullable();
            $table->string('plan_codigo',60)->nullable()->index();
            $table->string('modalidad',20)->nullable();
            $table->text('mensaje')->nullable();
            $table->string('estado',30)->default('pendiente')->index();
            $table->foreignId('revisado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('revisado_at')->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('invitacion_id')->nullable()->constrained('invitaciones_clientes')->nullOnDelete();
            $table->timestamps();
            $table->index(['telefono','estado']);
        });
    }

    public function down(): void { Schema::dropIfExists('solicitudes_acceso_viti'); }
};
