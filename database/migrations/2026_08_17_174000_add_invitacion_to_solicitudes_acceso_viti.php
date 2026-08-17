<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('solicitudes_acceso_viti') || !Schema::hasTable('invitaciones_clientes')) return;

        Schema::table('solicitudes_acceso_viti', function (Blueprint $table): void {
            if (!Schema::hasColumn('solicitudes_acceso_viti', 'invitacion_id')) {
                $table->foreignId('invitacion_id')
                    ->nullable()
                    ->after('revisado_at')
                    ->constrained('invitaciones_clientes')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('solicitudes_acceso_viti') && Schema::hasColumn('solicitudes_acceso_viti', 'invitacion_id')) {
            Schema::table('solicitudes_acceso_viti', function (Blueprint $table): void {
                $table->dropForeign(['invitacion_id']);
                $table->dropColumn('invitacion_id');
            });
        }
    }
};
