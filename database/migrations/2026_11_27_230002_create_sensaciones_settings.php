<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('sensaciones_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->json('settings');
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('sensaciones_settings'); }
};
