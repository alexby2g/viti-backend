<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electrofrio_tecnicos', function (Blueprint $table): void {
            $table->foreignId('usuario_id')->nullable()->after('empresa_id')->constrained('usuarios')->nullOnDelete();
            $table->unique(['empresa_id', 'usuario_id'], 'electrofrio_tecnico_empresa_usuario_unique');
        });

        Schema::table('usuarios', function (Blueprint $table): void {
            $table->foreignId('electrofrio_cliente_id')->nullable()->after('cliente_id')->unique()->constrained('electrofrio_clientes')->nullOnDelete();
        });

        Schema::table('conversaciones', function (Blueprint $table): void {
            $table->foreignId('electrofrio_cliente_id')->nullable()->after('cliente_id')->constrained('electrofrio_clientes')->cascadeOnDelete();
            $table->index(['empresa_id', 'electrofrio_cliente_id', 'contexto'], 'conversaciones_electrofrio_cliente_idx');
        });

        Schema::table('conversaciones', function (Blueprint $table): void {
            $table->unsignedBigInteger('cliente_id')->nullable()->change();
        });

        Schema::table('atencion_sesiones', function (Blueprint $table): void {
            $table->foreignId('electrofrio_cliente_id')->nullable()->after('cliente_id')->constrained('electrofrio_clientes')->cascadeOnDelete();
        });
        Schema::table('atencion_sesiones', function (Blueprint $table): void {
            $table->unsignedBigInteger('cliente_id')->nullable()->change();
        });

        Schema::table('llamadas', function (Blueprint $table): void {
            $table->foreignId('electrofrio_cliente_id')->nullable()->after('cliente_id')->constrained('electrofrio_clientes')->cascadeOnDelete();
        });
        Schema::table('llamadas', function (Blueprint $table): void {
            $table->unsignedBigInteger('cliente_id')->nullable()->change();
        });

        DB::table('conversaciones')
            ->where('contexto', 'electrofrio')
            ->whereNull('electrofrio_cliente_id')
            ->update(['canal_principal' => false, 'updated_at' => now()]);

        DB::table('electrofrio_tecnicos')
            ->whereNull('usuario_id')
            ->whereNotNull('telefono')
            ->orderBy('id')
            ->each(function (object $technician): void {
                $userId = DB::table('empresa_usuario as eu')
                    ->join('usuarios as u', 'u.id', '=', 'eu.usuario_id')
                    ->where('eu.empresa_id', $technician->empresa_id)
                    ->where('eu.activo', true)
                    ->where('u.telefono', $technician->telefono)
                    ->value('u.id');
                if ($userId) DB::table('electrofrio_tecnicos')->where('id', $technician->id)->update(['usuario_id' => $userId]);
            });
    }

    public function down(): void
    {
        Schema::table('llamadas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('electrofrio_cliente_id');
        });
        Schema::table('atencion_sesiones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('electrofrio_cliente_id');
        });
        Schema::table('conversaciones', function (Blueprint $table): void {
            $table->dropIndex('conversaciones_electrofrio_cliente_idx');
            $table->dropConstrainedForeignId('electrofrio_cliente_id');
        });
        Schema::table('usuarios', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('electrofrio_cliente_id');
        });
        Schema::table('electrofrio_tecnicos', function (Blueprint $table): void {
            $table->dropUnique('electrofrio_tecnico_empresa_usuario_unique');
            $table->dropConstrainedForeignId('usuario_id');
        });
    }
};
