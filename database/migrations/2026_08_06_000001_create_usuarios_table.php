<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 120);
            $table->string('apellido', 120)->nullable();
            $table->string('usuario', 80)->unique();
            $table->string('telefono', 30)->nullable()->unique();
            $table->string('password');
            $table->string('rol', 30)->default('administrador')->index();
            $table->string('estado', 20)->default('activo')->index();
            $table->timestamp('ultimo_acceso')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
