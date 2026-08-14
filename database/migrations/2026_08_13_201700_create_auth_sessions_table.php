<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('tipo', 16)->index();
            $table->char('session_key_hash', 64)->nullable()->unique();
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->unique();
            $table->string('device_name', 120)->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('browser', 60)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->char('fingerprint_hash', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revoked_reason', 120)->nullable();
            $table->timestamps();
            $table->index(['usuario_id','revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_sessions');
    }
};
