<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['proyecto_pagos','suscripcion_pagos'] as $tableName) {
            if (!Schema::hasTable($tableName)) continue;

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (!Schema::hasColumn($tableName, 'estado_revision')) {
                    $table->string('estado_revision', 30)->default('confirmado')->index();
                }
                if (!Schema::hasColumn($tableName, 'origen')) {
                    $table->string('origen', 30)->default('admin');
                }
                if (!Schema::hasColumn($tableName, 'comprobante_nombre')) {
                    $table->string('comprobante_nombre')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'comprobante_mime')) {
                    $table->string('comprobante_mime', 120)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'enviado_at')) {
                    $table->timestamp('enviado_at')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'revisado_at')) {
                    $table->timestamp('revisado_at')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'revisado_por')) {
                    $table->unsignedBigInteger('revisado_por')->nullable()->index();
                }
                if (!Schema::hasColumn($tableName, 'motivo_revision')) {
                    $table->string('motivo_revision', 500)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['proyecto_pagos','suscripcion_pagos'] as $tableName) {
            if (!Schema::hasTable($tableName)) continue;
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                foreach (['estado_revision','origen','comprobante_nombre','comprobante_mime','enviado_at','revisado_at','revisado_por','motivo_revision'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) $table->dropColumn($column);
                }
            });
        }
    }
};
