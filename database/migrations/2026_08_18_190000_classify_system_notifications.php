<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('alertas_saas', function (Blueprint $table) {
            if (!Schema::hasColumn('alertas_saas', 'canal')) $table->string('canal', 32)->default('notification')->index();
            if (!Schema::hasColumn('alertas_saas', 'categoria')) $table->string('categoria', 64)->nullable()->index();
            if (!Schema::hasColumn('alertas_saas', 'recurso_tipo')) $table->string('recurso_tipo', 64)->nullable();
            if (!Schema::hasColumn('alertas_saas', 'recurso_id')) $table->unsignedBigInteger('recurso_id')->nullable();
            if (!Schema::hasColumn('alertas_saas', 'data')) $table->json('data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('alertas_saas', function (Blueprint $table) {
            foreach (['canal','categoria','recurso_tipo','recurso_id','data'] as $column) {
                if (Schema::hasColumn('alertas_saas', $column)) $table->dropColumn($column);
            }
        });
    }
};
