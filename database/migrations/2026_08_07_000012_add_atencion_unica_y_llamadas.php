<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('conversaciones', function (Blueprint $table): void {
            $table->boolean('canal_principal')->default(false)->after('estado')->index();
            $table->foreignId('responsable_usuario_id')->nullable()->after('cliente_id')->constrained('usuarios')->nullOnDelete();
        });

        Schema::create('llamadas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversacion_id')->constrained('conversaciones')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('iniciada_por_usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('receptor_usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('tipo', 20)->default('audio');
            $table->string('estado', 30)->default('llamando')->index();
            $table->longText('offer_sdp')->nullable();
            $table->longText('answer_sdp')->nullable();
            $table->timestamp('contestada_at')->nullable();
            $table->timestamp('finalizada_at')->nullable();
            $table->timestamps();
            $table->index(['receptor_usuario_id', 'estado']);
            $table->index(['cliente_id', 'created_at']);
        });

        Schema::create('llamada_senales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('llamada_id')->constrained('llamadas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('tipo', 30)->default('ice');
            $table->json('payload');
            $table->timestamps();
            $table->index(['llamada_id', 'id']);
        });

        $adminId = DB::table('usuarios')
            ->where('estado', 'activo')
            ->where('rol', 'superadmin')
            ->orderBy('id')
            ->value('id');

        $now = now();
        $clientes = DB::table('clientes')->select('id')->orderBy('id')->get();

        foreach ($clientes as $cliente) {
            $conversationId = DB::table('conversaciones')
                ->where('cliente_id', $cliente->id)
                ->orderByDesc('ultimo_mensaje_at')
                ->orderByDesc('id')
                ->value('id');

            if ($conversationId) {
                DB::table('conversaciones')->where('cliente_id', $cliente->id)->update(['canal_principal' => false]);
                DB::table('conversaciones')->where('id', $conversationId)->update([
                    'canal_principal' => true,
                    'responsable_usuario_id' => $adminId,
                    'asunto' => 'Atención VITI',
                    'estado' => 'abierta',
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('conversaciones')->insert([
                    'cliente_id' => $cliente->id,
                    'responsable_usuario_id' => $adminId,
                    'solicitud_id' => null,
                    'proyecto_id' => null,
                    'asunto' => 'Atención VITI',
                    'estado' => 'abierta',
                    'canal_principal' => true,
                    'ultimo_mensaje_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('llamada_senales');
        Schema::dropIfExists('llamadas');

        Schema::table('conversaciones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('responsable_usuario_id');
            $table->dropColumn('canal_principal');
        });
    }
};
