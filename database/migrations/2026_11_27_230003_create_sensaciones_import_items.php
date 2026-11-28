<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('sensaciones_import_items', function (Blueprint $table) {
            $table->id();
            $table->string('source',64);
            $table->string('source_key',64);
            $table->string('fingerprint',64);
            $table->string('kind',16);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
            $table->unique(['source','source_key']);
        });
    }
    public function down(): void { Schema::dropIfExists('sensaciones_import_items'); }
};
