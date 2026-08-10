<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_sistema', function (Blueprint $table): void {
            $table->foreignId('plan_viti_id')->nullable()->after('cuestionario_id')->constrained('planes_viti')->nullOnDelete();
            $table->string('forma_pago_preferida', 40)->nullable()->after('presupuesto_estimado');
            $table->boolean('acuerdo_comercial_requerido')->default(false)->after('forma_pago_preferida');
            $table->boolean('acuerdo_comercial_aceptado')->default(false)->after('acuerdo_comercial_requerido');
            $table->string('acuerdo_comercial_nombre', 180)->nullable()->after('acuerdo_comercial_aceptado');
            $table->date('acuerdo_comercial_fecha')->nullable()->after('acuerdo_comercial_nombre');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_sistema', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_viti_id');
            $table->dropColumn([
                'forma_pago_preferida',
                'acuerdo_comercial_requerido',
                'acuerdo_comercial_aceptado',
                'acuerdo_comercial_nombre',
                'acuerdo_comercial_fecha',
            ]);
        });
    }
};
