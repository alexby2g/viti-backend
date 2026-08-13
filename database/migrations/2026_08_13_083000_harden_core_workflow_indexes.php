<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_sistema', function (Blueprint $table): void {
            $table->index(['empresa_id','estado'], 'solicitudes_empresa_estado_idx');
        });

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->index(['empresa_id','estado'], 'proyectos_empresa_estado_idx');
            $table->index(['empresa_id','fase'], 'proyectos_empresa_fase_idx');
        });

        Schema::table('aplicaciones', function (Blueprint $table): void {
            $table->index(['empresa_id','estado'], 'aplicaciones_empresa_estado_idx');
            $table->index(['empresa_id','entorno'], 'aplicaciones_empresa_entorno_idx');
        });
    }

    public function down(): void
    {
        Schema::table('aplicaciones', function (Blueprint $table): void {
            $table->dropIndex('aplicaciones_empresa_entorno_idx');
            $table->dropIndex('aplicaciones_empresa_estado_idx');
        });

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropIndex('proyectos_empresa_fase_idx');
            $table->dropIndex('proyectos_empresa_estado_idx');
        });

        Schema::table('solicitudes_sistema', function (Blueprint $table): void {
            $table->dropIndex('solicitudes_empresa_estado_idx');
        });
    }
};
