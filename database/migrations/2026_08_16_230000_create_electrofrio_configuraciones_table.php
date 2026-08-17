<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electrofrio_configuraciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre_sistema', 140)->default('Sistema de Gestión de Servicios de Aire Acondicionado');
            $table->string('nombre_corto', 60)->default('Aires Acondicionados');
            $table->string('logo_url', 500)->nullable();
            $table->string('telefono', 40)->nullable();
            $table->string('correo', 180)->nullable();
            $table->string('direccion', 300)->nullable();
            $table->string('color_primario', 20)->default('#0B5F7A');
            $table->string('color_secundario', 20)->default('#12B8C8');
            $table->string('moneda', 8)->default('BOB');
            $table->unsignedInteger('garantia_dias_default')->default(0);
            $table->json('tipos_servicio')->nullable();
            $table->json('tipos_equipo')->nullable();
            $table->json('metodos_pago')->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();

            $table->unique('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electrofrio_configuraciones');
    }
};
