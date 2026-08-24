<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('proyecto_repositorios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();
            $table->string('tipo', 30)->default('frontend');
            $table->string('proveedor', 30)->default('github');
            $table->string('nombre', 180);
            $table->string('url', 500);
            $table->string('rama_principal', 120)->default('main');
            $table->boolean('privado')->default(true);
            $table->text('descripcion')->nullable();
            $table->timestamps();
            $table->index(['proyecto_id', 'tipo']);
        });

        Schema::create('proyecto_miembros', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('rol', 40)->default('desarrollador');
            $table->json('permisos')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['proyecto_id', 'usuario_id']);
            $table->index(['usuario_id', 'activo']);
        });

        Schema::create('proyecto_ambientes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->string('nombre', 120);
            $table->string('frontend_url', 500)->nullable();
            $table->string('backend_url', 500)->nullable();
            $table->string('proveedor_frontend', 60)->nullable();
            $table->string('proveedor_backend', 60)->nullable();
            $table->string('base_datos_referencia', 180)->nullable();
            $table->string('estado', 30)->default('pendiente')->index();
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->unique(['proyecto_id', 'tipo']);
        });

        Schema::create('proyecto_dominios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();
            $table->foreignId('ambiente_id')->nullable()->constrained('proyecto_ambientes')->nullOnDelete();
            $table->string('dominio', 255)->unique();
            $table->string('tipo', 30)->default('web');
            $table->string('estado', 30)->default('pendiente')->index();
            $table->timestamp('verificado_at')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proyecto_dominios');
        Schema::dropIfExists('proyecto_ambientes');
        Schema::dropIfExists('proyecto_miembros');
        Schema::dropIfExists('proyecto_repositorios');
    }
};
