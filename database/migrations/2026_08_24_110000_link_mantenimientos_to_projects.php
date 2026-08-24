<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('mantenimientos', function (Blueprint $table): void {
            $table->foreignId('proyecto_id')->nullable()->after('aplicacion_id')->constrained('proyectos')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->after('asignado_a')->constrained('usuarios')->nullOnDelete();
            $table->index(['proyecto_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::table('mantenimientos', function (Blueprint $table): void {
            $table->dropForeign(['proyecto_id']);
            $table->dropForeign(['creado_por']);
            $table->dropIndex(['proyecto_id', 'estado']);
            $table->dropColumn(['proyecto_id', 'creado_por']);
        });
    }
};
