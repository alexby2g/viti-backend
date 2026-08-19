<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('auditoria')) {
            return;
        }

        Schema::table('auditoria', function (Blueprint $table): void {
            if (!Schema::hasColumn('auditoria', 'empresa_id')) {
                $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            }

            if (!Schema::hasColumn('auditoria', 'aplicacion_id')) {
                $table->foreignId('aplicacion_id')->nullable()->constrained('aplicaciones')->nullOnDelete();
            }
        });

        Schema::table('auditoria', function (Blueprint $table): void {
            $table->index(['empresa_id', 'created_at']);
            $table->index(['aplicacion_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('auditoria')) {
            return;
        }

        Schema::table('auditoria', function (Blueprint $table): void {
            if (Schema::hasColumn('auditoria', 'aplicacion_id')) {
                $table->dropForeign(['aplicacion_id']);
                $table->dropIndex(['aplicacion_id', 'created_at']);
                $table->dropColumn('aplicacion_id');
            }

            if (Schema::hasColumn('auditoria', 'empresa_id')) {
                $table->dropForeign(['empresa_id']);
                $table->dropIndex(['empresa_id', 'created_at']);
                $table->dropColumn('empresa_id');
            }
        });
    }
};
