<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->string('documento', 30)->nullable();
        });

        DB::table('usuarios')
            ->whereNotNull('cliente_id')
            ->orderBy('id')
            ->each(function ($usuario): void {
                $documento = DB::table('clientes')->where('id', $usuario->cliente_id)->value('documento');
                if (!$documento) return;

                $digits = preg_replace('/\D+/', '', (string) $documento);
                if ($digits === '' || DB::table('usuarios')->where('documento', $digits)->exists()) return;
                DB::table('usuarios')->where('id', $usuario->id)->update(['documento' => $digits]);
            });

        Schema::table('usuarios', function (Blueprint $table): void {
            $table->unique('documento', 'usuarios_documento_unique');
        });

        Schema::table('conversaciones', function (Blueprint $table): void {
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('aplicacion_id')->nullable()->constrained('aplicaciones')->nullOnDelete();
            $table->string('contexto', 30)->default('viti');
            $table->foreignId('eliminada_por_usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->softDeletes();
            $table->index(['contexto', 'canal_principal'], 'conversaciones_contexto_principal_idx');
            $table->index(['cliente_id', 'contexto'], 'conversaciones_cliente_contexto_idx');
            $table->index(['empresa_id', 'aplicacion_id'], 'conversaciones_empresa_app_idx');
        });

        DB::table('conversaciones')->whereNull('contexto')->update(['contexto' => 'viti']);

        Schema::table('mensajes', function (Blueprint $table): void {
            $table->uuid('client_request_id')->nullable();
            $table->timestamp('entregado_at')->nullable();
            $table->timestamp('editado_at')->nullable();
            $table->timestamp('eliminado_at')->nullable();
            $table->foreignId('eliminado_por_usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->unique('client_request_id', 'mensajes_client_request_unique');
        });

        Schema::create('chat_presencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversacion_id')->constrained('conversaciones')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->timestamp('ultimo_ping_at');
            $table->timestamp('escribiendo_hasta')->nullable();
            $table->timestamps();
            $table->unique(['conversacion_id', 'usuario_id'], 'chat_presencia_conversacion_usuario_unique');
            $table->index('ultimo_ping_at', 'chat_presencia_ping_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_presencias');

        Schema::table('mensajes', function (Blueprint $table): void {
            $table->dropUnique('mensajes_client_request_unique');
            $table->dropConstrainedForeignId('eliminado_por_usuario_id');
            $table->dropColumn(['client_request_id', 'entregado_at', 'editado_at', 'eliminado_at']);
        });

        Schema::table('conversaciones', function (Blueprint $table): void {
            $table->dropIndex('conversaciones_contexto_principal_idx');
            $table->dropIndex('conversaciones_cliente_contexto_idx');
            $table->dropIndex('conversaciones_empresa_app_idx');
            $table->dropConstrainedForeignId('eliminada_por_usuario_id');
            $table->dropConstrainedForeignId('aplicacion_id');
            $table->dropConstrainedForeignId('empresa_id');
            $table->dropSoftDeletes();
            $table->dropColumn('contexto');
        });

        Schema::table('usuarios', function (Blueprint $table): void {
            $table->dropUnique('usuarios_documento_unique');
            $table->dropColumn('documento');
        });
    }
};
