<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('invitaciones_clientes')) return;

        Schema::table('invitaciones_clientes', function (Blueprint $table): void {
            if (!Schema::hasColumn('invitaciones_clientes', 'codigo')) {
                $table->string('codigo', 20)->nullable()->unique()->after('token');
            }
        });

        DB::table('invitaciones_clientes')
            ->whereNull('codigo')
            ->orderBy('id')
            ->eachById(function (object $invitation): void {
                do {
                    $codigo = 'VITI-'.Str::upper(Str::random(6));
                } while (DB::table('invitaciones_clientes')->where('codigo', $codigo)->exists());

                DB::table('invitaciones_clientes')
                    ->where('id', $invitation->id)
                    ->update(['codigo' => $codigo, 'updated_at' => now()]);
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('invitaciones_clientes') && Schema::hasColumn('invitaciones_clientes', 'codigo')) {
            Schema::table('invitaciones_clientes', function (Blueprint $table): void {
                $table->dropUnique(['codigo']);
                $table->dropColumn('codigo');
            });
        }
    }
};
