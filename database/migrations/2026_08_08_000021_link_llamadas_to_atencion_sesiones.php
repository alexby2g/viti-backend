<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('llamadas', function (Blueprint $table): void {
            $table->foreignId('atencion_sesion_id')->nullable()->after('cliente_id')->constrained('atencion_sesiones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('llamadas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('atencion_sesion_id');
        });
    }
};
