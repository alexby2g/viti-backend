<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->string('ci_expedido', 20)->nullable()->after('documento');
            $table->boolean('foto_verificada')->default(false)->after('foto_path');
            $table->timestamp('perfil_completo_at')->nullable()->after('canal_origen');
        });

        Schema::table('proyecto_avances', function (Blueprint $table): void {
            $table->string('area', 30)->default('general')->after('fase')->index();
        });

        Schema::create('conversaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('solicitud_id')->nullable()->constrained('solicitudes_sistema')->nullOnDelete();
            $table->foreignId('proyecto_id')->nullable()->constrained('proyectos')->nullOnDelete();
            $table->string('asunto', 180)->default('Atención y seguimiento');
            $table->string('estado', 20)->default('abierta')->index();
            $table->timestamp('ultimo_mensaje_at')->nullable()->index();
            $table->timestamps();
            $table->index(['cliente_id','estado']);
        });

        Schema::create('mensajes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversacion_id')->constrained('conversaciones')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->text('mensaje');
            $table->timestamp('leido_at')->nullable();
            $table->timestamps();
            $table->index(['conversacion_id','created_at']);
        });

        Schema::table('aplicaciones', function (Blueprint $table): void {
            $table->string('tipo', 30)->default('web')->after('version');
            $table->string('tecnologias', 255)->nullable()->after('tipo');
            $table->string('repositorio_url')->nullable()->after('url_administracion');
            $table->string('proveedor_hosting', 100)->nullable()->after('repositorio_url');
        });
    }

    public function down(): void
    {
        Schema::table('aplicaciones', function (Blueprint $table): void {
            $table->dropColumn(['tipo','tecnologias','repositorio_url','proveedor_hosting']);
        });
        Schema::dropIfExists('mensajes');
        Schema::table('proyecto_avances', function (Blueprint $table): void { $table->dropColumn('area'); });
        Schema::dropIfExists('conversaciones');
        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropColumn(['ci_expedido','foto_verificada','perfil_completo_at']);
        });
    }
};
