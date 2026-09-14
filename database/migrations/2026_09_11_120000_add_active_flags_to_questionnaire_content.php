<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('cuestionario_secciones', 'activo')) {
            Schema::table('cuestionario_secciones', function (Blueprint $table): void {
                $table->boolean('activo')->default(true)->after('descripcion');
            });
        }

        if (!Schema::hasColumn('cuestionario_preguntas', 'activo')) {
            Schema::table('cuestionario_preguntas', function (Blueprint $table): void {
                $table->boolean('activo')->default(true)->after('obligatoria');
            });
        }

        // Las preguntas históricas sin texto no deben aparecer en formularios públicos.
        DB::table('cuestionario_preguntas')
            ->whereNull('pregunta')
            ->orWhereRaw("TRIM(pregunta) = ''")
            ->update(['activo' => false]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('cuestionario_preguntas', 'activo')) {
            Schema::table('cuestionario_preguntas', function (Blueprint $table): void {
                $table->dropColumn('activo');
            });
        }
        if (Schema::hasColumn('cuestionario_secciones', 'activo')) {
            Schema::table('cuestionario_secciones', function (Blueprint $table): void {
                $table->dropColumn('activo');
            });
        }
    }
};
