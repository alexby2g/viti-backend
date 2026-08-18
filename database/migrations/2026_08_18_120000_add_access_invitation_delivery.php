<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->string('correo', 160)->nullable()->unique()->after('whatsapp');
        });

        Schema::table('invitaciones_clientes', function (Blueprint $table): void {
            $table->string('correo_destino', 160)->nullable()->after('solicitud_id');
            $table->timestamp('enviada_at')->nullable()->after('expira_at');
            $table->timestamp('ultimo_envio_at')->nullable()->after('enviada_at');
            $table->unsignedSmallInteger('intentos_envio')->default(0)->after('ultimo_envio_at');
            $table->timestamp('revocada_at')->nullable()->after('usada_at');
            $table->text('error_envio')->nullable()->after('revocada_at');
        });
    }

    public function down(): void
    {
        Schema::table('invitaciones_clientes', function (Blueprint $table): void {
            $table->dropColumn([
                'correo_destino','enviada_at','ultimo_envio_at','intentos_envio','revocada_at','error_envio',
            ]);
        });

        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropUnique(['correo']);
            $table->dropColumn('correo');
        });
    }
};
