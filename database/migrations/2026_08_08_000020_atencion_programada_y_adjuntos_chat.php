<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('atencion_sesiones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversacion_id')->constrained('conversaciones')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('solicitada_por_usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('aprobada_por_usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('modalidad', 20)->default('video')->index(); // audio, video, pantalla
            $table->string('estado', 20)->default('solicitada')->index(); // solicitada, aprobada, rechazada, cancelada, finalizada
            $table->text('motivo')->nullable();
            $table->text('nota_admin')->nullable();
            $table->timestamp('programada_para')->nullable()->index();
            $table->timestamp('habilitada_desde')->nullable();
            $table->timestamp('habilitada_hasta')->nullable()->index();
            $table->timestamp('aprobada_at')->nullable();
            $table->timestamps();
            $table->index(['conversacion_id','estado']);
            $table->index(['cliente_id','estado']);
        });

        Schema::table('mensajes', function (Blueprint $table): void {
            $table->string('tipo', 20)->default('texto')->after('usuario_id');
            $table->string('archivo_path')->nullable()->after('mensaje');
            $table->string('archivo_nombre')->nullable()->after('archivo_path');
            $table->string('archivo_mime', 120)->nullable()->after('archivo_nombre');
            $table->unsignedBigInteger('archivo_tamano')->nullable()->after('archivo_mime');
        });
    }

    public function down(): void
    {
        Schema::table('mensajes', function (Blueprint $table): void {
            $table->dropColumn(['tipo','archivo_path','archivo_nombre','archivo_mime','archivo_tamano']);
        });
        Schema::dropIfExists('atencion_sesiones');
    }
};
