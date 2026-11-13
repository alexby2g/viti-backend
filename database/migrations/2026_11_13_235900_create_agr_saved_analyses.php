<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A job is linked to a solicitud only if it was created by the
        // administrator-only request analysis endpoint.
        Schema::table('agr_local_inference_jobs', function (Blueprint $table): void {
            $table->foreignId('analysis_solicitud_id')
                ->nullable()
                ->constrained('solicitudes_sistema')
                ->nullOnDelete();
        });

        Schema::create('agr_saved_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes_sistema')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->uuid('source_job_id')->unique();
            $table->unsignedInteger('version');
            $table->string('estado', 20)->default('borrador');
            $table->longText('encrypted_content');
            $table->timestamps();
            $table->unique(['solicitud_id', 'version']);
            $table->index(['solicitud_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agr_saved_analyses');
        Schema::table('agr_local_inference_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('analysis_solicitud_id');
        });
    }
};
