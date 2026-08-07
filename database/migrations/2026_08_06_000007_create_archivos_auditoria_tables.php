<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('archivos', function (Blueprint $table): void {
            $table->id();
            $table->morphs('adjuntable');
            $table->foreignId('subido_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('categoria', 50)->default('general')->index();
            $table->string('nombre_original');
            $table->string('ruta');
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('tamano')->default(0);
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        Schema::create('auditoria', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('accion', 100)->index();
            $table->string('entidad_tipo', 120)->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->text('descripcion')->nullable();
            $table->json('datos')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['entidad_tipo', 'entidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria');
        Schema::dropIfExists('archivos');
    }
};
