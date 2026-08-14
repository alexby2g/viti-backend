<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electrofrio_evidencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('orden_id')->constrained('electrofrio_ordenes')->cascadeOnDelete();
            $table->foreignId('subido_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('categoria', 30);
            $table->string('nombre_original', 255);
            $table->string('ruta', 500);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('tamano')->default(0);
            $table->string('descripcion', 1000)->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'orden_id', 'categoria']);
            $table->index(['empresa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electrofrio_evidencias');
    }
};
