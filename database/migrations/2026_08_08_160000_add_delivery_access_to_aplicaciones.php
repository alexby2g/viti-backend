<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aplicaciones', function (Blueprint $table): void {
            $table->boolean('acceso_cliente')->default(false)->after('estado');
            $table->timestamp('entregado_at')->nullable()->after('acceso_cliente');
        });
    }

    public function down(): void
    {
        Schema::table('aplicaciones', function (Blueprint $table): void {
            $table->dropColumn(['acceso_cliente','entregado_at']);
        });
    }
};
