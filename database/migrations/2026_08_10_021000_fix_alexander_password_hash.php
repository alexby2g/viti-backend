<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('usuarios')
            ->where('usuario', 'alexander_electrofrio')
            ->update([
                'password' => '$argon2id$v=19$m=65536,t=4,p=1$Moz9lY0eJOW3YU3/ont6yQ$S0BZdrqr6L3xWGGTQT+B2Gq5VxXXxHROQzZyy3hB9NY',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // La contraseña válida se conserva para no bloquear la cuenta.
    }
};
