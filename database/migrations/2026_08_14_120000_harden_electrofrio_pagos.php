<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electrofrio_pagos', function (Blueprint $table): void {
            $table->string('tipo', 20)->default('abono')->after('monto');
            $table->text('notas')->nullable()->after('referencia');
            $table->foreignId('registrado_por')->nullable()->after('estado')->constrained('usuarios')->nullOnDelete();
            $table->string('idempotency_key', 64)->nullable()->after('registrado_por');
            $table->timestamp('anulado_at')->nullable()->after('pagado_at');
            $table->foreignId('anulado_por')->nullable()->after('anulado_at')->constrained('usuarios')->nullOnDelete();
            $table->string('motivo_anulacion', 1000)->nullable()->after('anulado_por');

            $table->unique(['empresa_id', 'idempotency_key']);
            $table->index(['empresa_id', 'orden_id', 'estado', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::table('electrofrio_pagos', function (Blueprint $table): void {
            $table->dropUnique(['empresa_id', 'idempotency_key']);
            $table->dropIndex(['empresa_id', 'orden_id', 'estado', 'tipo']);
            $table->dropConstrainedForeignId('anulado_por');
            $table->dropColumn(['motivo_anulacion', 'anulado_at', 'idempotency_key']);
            $table->dropConstrainedForeignId('registrado_por');
            $table->dropColumn(['notas', 'tipo']);
        });
    }
};
