<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aplicaciones', function (Blueprint $table): void {
            $table->boolean('acceso_bloqueado_manual')->default(false)->after('acceso_cliente');
            $table->string('bloqueo_manual_motivo', 500)->nullable()->after('acceso_bloqueado_manual');
            $table->dateTime('bloqueado_manualmente_at')->nullable()->after('bloqueo_manual_motivo');
            $table->unsignedBigInteger('bloqueado_manualmente_por')->nullable()->after('bloqueado_manualmente_at');
            $table->index(['acceso_bloqueado_manual', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::table('aplicaciones', function (Blueprint $table): void {
            $table->dropIndex(['acceso_bloqueado_manual', 'estado']);
            $table->dropColumn([
                'acceso_bloqueado_manual',
                'bloqueo_manual_motivo',
                'bloqueado_manualmente_at',
                'bloqueado_manualmente_por',
            ]);
        });
    }
};
