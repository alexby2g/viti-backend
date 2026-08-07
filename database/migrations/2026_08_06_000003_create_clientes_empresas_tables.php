<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 180);
            $table->string('telefono', 30)->unique();
            $table->string('whatsapp', 30)->nullable();
            $table->string('documento', 50)->nullable();
            $table->string('ciudad', 100)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->text('observaciones')->nullable();
            $table->string('estado', 30)->default('prospecto')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('empresas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('codigo', 30)->unique();
            $table->string('nombre_comercial', 180);
            $table->string('razon_social', 200)->nullable();
            $table->string('actividad', 200)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('ciudad', 100)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('logo_path')->nullable();
            $table->text('observaciones')->nullable();
            $table->string('estado', 30)->default('prospecto')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['cliente_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
        Schema::dropIfExists('clientes');
    }
};
