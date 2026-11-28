<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('sensaciones_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('usuario_id');
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('kind', 16);
            $table->string('path');
            $table->string('mime', 100);
            $table->boolean('published')->default(false)->index();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('sensaciones_media');
    }
};
