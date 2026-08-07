<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('plataforma', 30)->default('android');
            $table->string('dispositivo', 120)->nullable();
            $table->boolean('activo')->default(true)->index();
            $table->timestamp('ultimo_registro_at')->nullable();
            $table->timestamps();
            $table->index(['usuario_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
