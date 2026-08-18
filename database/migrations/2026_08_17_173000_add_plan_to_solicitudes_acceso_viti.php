<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('solicitudes_acceso_viti')) return;

        Schema::table('solicitudes_acceso_viti', function (Blueprint $table): void {
            if (!Schema::hasColumn('solicitudes_acceso_viti', 'plan_codigo')) {
                $table->string('plan_codigo', 60)->nullable()->index()->after('actividad');
            }
            if (!Schema::hasColumn('solicitudes_acceso_viti', 'modalidad')) {
                $table->string('modalidad', 20)->nullable()->after('plan_codigo');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('solicitudes_acceso_viti')) return;

        Schema::table('solicitudes_acceso_viti', function (Blueprint $table): void {
            if (Schema::hasColumn('solicitudes_acceso_viti', 'modalidad')) $table->dropColumn('modalidad');
            if (Schema::hasColumn('solicitudes_acceso_viti', 'plan_codigo')) $table->dropColumn('plan_codigo');
        });
    }
};
