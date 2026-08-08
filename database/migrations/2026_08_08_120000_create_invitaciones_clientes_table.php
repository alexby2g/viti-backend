<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invitaciones_clientes', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 80)->unique();
            $table->foreignId('creada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('solicitud_id')->nullable()->constrained('solicitudes_sistema')->nullOnDelete();
            $table->string('estado', 30)->default('pendiente')->index();
            $table->timestamp('expira_at')->nullable()->index();
            $table->timestamp('usada_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitaciones_clientes');
    }
};
