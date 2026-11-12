<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('irenet_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('nombre', 80);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expira_at');
            $table->timestamp('revocado_at')->nullable();
            $table->timestamp('ultimo_acceso_at')->nullable();
            $table->timestamps();
            $table->index(['usuario_id', 'revocado_at', 'expira_at'], 'irenet_devices_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('irenet_devices');
    }
};
