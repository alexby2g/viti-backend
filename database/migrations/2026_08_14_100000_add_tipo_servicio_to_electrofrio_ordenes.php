<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electrofrio_ordenes', function (Blueprint $table): void {
            $table->string('tipo_servicio', 120)->nullable()->after('problema_reportado');
            $table->index(['empresa_id', 'tipo_servicio']);
        });
    }

    public function down(): void
    {
        Schema::table('electrofrio_ordenes', function (Blueprint $table): void {
            $table->dropIndex(['empresa_id', 'tipo_servicio']);
            $table->dropColumn('tipo_servicio');
        });
    }
};
