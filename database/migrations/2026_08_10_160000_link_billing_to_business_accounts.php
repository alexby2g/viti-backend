<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table): void {
            $table->string('metodo_pago_preferido', 30)->default('qr')->after('moneda');
        });

        Schema::table('proyecto_pagos', function (Blueprint $table): void {
            $table->foreignId('pagador_usuario_id')->nullable()->after('empresa_id')->constrained('usuarios')->nullOnDelete();
            $table->index(['empresa_id', 'fecha_pago']);
        });

        Schema::table('suscripcion_pagos', function (Blueprint $table): void {
            $table->foreignId('empresa_id')->nullable()->after('suscripcion_id')->constrained('empresas')->nullOnDelete();
            $table->foreignId('pagador_usuario_id')->nullable()->after('empresa_id')->constrained('usuarios')->nullOnDelete();
            $table->index(['empresa_id', 'fecha_pago']);
        });

        DB::table('suscripcion_pagos')
            ->whereNull('empresa_id')
            ->update([
                'empresa_id' => DB::raw('(SELECT empresa_id FROM suscripciones WHERE suscripciones.id = suscripcion_pagos.suscripcion_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('suscripcion_pagos', function (Blueprint $table): void {
            $table->dropIndex(['empresa_id', 'fecha_pago']);
            $table->dropConstrainedForeignId('pagador_usuario_id');
            $table->dropConstrainedForeignId('empresa_id');
        });

        Schema::table('proyecto_pagos', function (Blueprint $table): void {
            $table->dropIndex(['empresa_id', 'fecha_pago']);
            $table->dropConstrainedForeignId('pagador_usuario_id');
        });

        Schema::table('empresas', function (Blueprint $table): void {
            $table->dropColumn('metodo_pago_preferido');
        });
    }
};
