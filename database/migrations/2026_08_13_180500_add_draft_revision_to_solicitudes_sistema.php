<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_sistema', function (Blueprint $table): void {
            if (!Schema::hasColumn('solicitudes_sistema', 'draft_revision')) {
                $table->unsignedBigInteger('draft_revision')->default(0)->after('prioridad');
            }
            if (!Schema::hasColumn('solicitudes_sistema', 'draft_saved_at')) {
                $table->timestamp('draft_saved_at')->nullable()->after('draft_revision');
            }
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_sistema', function (Blueprint $table): void {
            if (Schema::hasColumn('solicitudes_sistema', 'draft_saved_at')) $table->dropColumn('draft_saved_at');
            if (Schema::hasColumn('solicitudes_sistema', 'draft_revision')) $table->dropColumn('draft_revision');
        });
    }
};
