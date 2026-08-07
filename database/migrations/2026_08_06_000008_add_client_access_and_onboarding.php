<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->string('foto_path')->nullable()->after('direccion');
            $table->string('canal_origen', 40)->default('viti')->after('estado')->index();
        });

        Schema::table('usuarios', function (Blueprint $table): void {
            $table->foreignId('cliente_id')->nullable()->unique()->after('id')->constrained('clientes')->nullOnDelete();
        });

        Schema::table('solicitudes_sistema', function (Blueprint $table): void {
            $table->unsignedBigInteger('empresa_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cliente_id');
        });
        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropColumn(['foto_path','canal_origen']);
        });
        Schema::table('solicitudes_sistema', function (Blueprint $table): void {
            $table->unsignedBigInteger('empresa_id')->nullable(false)->change();
        });
    }
};
