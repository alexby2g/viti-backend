<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electrofrio_fichas_tecnicas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('equipo_id')->constrained('electrofrio_equipos')->cascadeOnDelete();
            $table->string('gas_refrigerante', 60)->nullable();
            $table->string('voltaje', 30)->nullable();
            $table->decimal('amperaje_nominal', 10, 2)->nullable();
            $table->decimal('presion_succion_psi', 10, 2)->nullable();
            $table->decimal('presion_descarga_psi', 10, 2)->nullable();
            $table->text('observaciones_tecnicas')->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'equipo_id']);
            $table->index(['empresa_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electrofrio_fichas_tecnicas');
    }
};
