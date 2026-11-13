<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agr_local_inference_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->longText('encrypted_prompt');
            $table->longText('encrypted_answer')->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index(['usuario_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agr_local_inference_jobs');
    }
};
