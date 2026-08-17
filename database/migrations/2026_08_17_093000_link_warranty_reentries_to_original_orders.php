<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electrofrio_ordenes', function (Blueprint $table): void {
            $table->foreignId('orden_origen_garantia_id')
                ->nullable()
                ->after('tipo_servicio')
                ->constrained('electrofrio_ordenes')
                ->nullOnDelete();
            $table->text('motivo_reingreso_garantia')->nullable()->after('orden_origen_garantia_id');
            $table->index(['empresa_id', 'orden_origen_garantia_id'], 'ef_ordenes_garantia_origen_idx');
        });
    }

    public function down(): void
    {
        Schema::table('electrofrio_ordenes', function (Blueprint $table): void {
            $table->dropIndex('ef_ordenes_garantia_origen_idx');
            $table->dropConstrainedForeignId('orden_origen_garantia_id');
            $table->dropColumn('motivo_reingreso_garantia');
        });
    }
};
