<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('usuarios')
            ->whereNotNull('cliente_id')
            ->where('rol', '<>', 'cliente')
            ->update(['rol' => 'cliente', 'updated_at' => now()]);

        $primarySuperAdminId = DB::table('usuarios')
            ->where('rol', 'superadmin')
            ->orderBy('id')
            ->value('id');

        if ($primarySuperAdminId) {
            DB::table('usuarios')
                ->where('rol', 'superadmin')
                ->where('id', '<>', $primarySuperAdminId)
                ->update(['rol' => 'administrador', 'updated_at' => now()]);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_cliente_role_check');
            DB::statement("ALTER TABLE usuarios ADD CONSTRAINT usuarios_cliente_role_check CHECK (cliente_id IS NULL OR rol = 'cliente')");
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS usuarios_unico_superadmin ON usuarios (rol) WHERE rol = 'superadmin'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS usuarios_unico_superadmin');
            DB::statement('ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_cliente_role_check');
        }
    }
};
