<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('sensaciones_collections', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->json('media_ids');
            $table->unsignedBigInteger('cover_id')->nullable();
            $table->boolean('published')->default(false)->index();
            $table->timestamps();
        });
        Schema::create('sensaciones_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('usuario_id');
            $table->string('title',150);
            $table->text('description')->nullable();
            $table->string('mime',100);
            $table->unsignedBigInteger('bytes');
            $table->timestamp('expires_at')->index();
            $table->unsignedBigInteger('media_id')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('sensaciones_uploads');
        Schema::dropIfExists('sensaciones_collections');
    }
};
