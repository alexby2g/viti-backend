<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('aplicaciones', function (Blueprint $table): void {
            $table->foreignId('aplicacion_origen_id')->nullable()->after('proyecto_id')->constrained('aplicaciones')->nullOnDelete();
            $table->text('descripcion')->nullable()->after('nombre');
            $table->string('icono')->nullable()->after('descripcion');
            $table->string('color_primario', 30)->nullable()->after('icono');
            $table->string('color_secundario', 30)->nullable()->after('color_primario');
            $table->jsonb('modulos')->nullable()->after('color_secundario');
            $table->jsonb('configuracion')->nullable()->after('modulos');
            $table->boolean('es_plantilla')->default(false)->after('configuracion')->index();
        });

        Schema::create('aplicacion_usuario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('rol', 50)->default('consulta');
            $table->jsonb('permisos')->nullable();
            $table->boolean('activo')->default(true)->index();
            $table->timestamps();
            $table->unique(['aplicacion_id', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicacion_usuario');
        Schema::table('aplicaciones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('aplicacion_origen_id');
            $table->dropColumn(['descripcion','icono','color_primario','color_secundario','modulos','configuracion','es_plantilla']);
        });
    }
};
