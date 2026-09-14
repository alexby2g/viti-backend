<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('aplicaciones')
            ->where('estado', 'publicado')
            ->update(['estado' => 'activo']);
    }

    public function down(): void
    {
        // No revertimos "activo" a "publicado" porque podría afectar
        // aplicaciones creadas legítimamente como activas.
    }
};
