<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            if (!Schema::hasColumn('clientes', 'whatsapp_business')) {
                $table->string('whatsapp_business', 30)->nullable()->after('whatsapp');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            if (Schema::hasColumn('clientes', 'whatsapp_business')) {
                $table->dropColumn('whatsapp_business');
            }
        });
    }
};
