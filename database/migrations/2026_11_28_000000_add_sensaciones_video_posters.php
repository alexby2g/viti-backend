<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('sensaciones_media',function(Blueprint $table){
            $table->string('poster_path')->nullable();
            $table->string('poster_mime',100)->nullable();
        });
    }
    public function down(): void {
        Schema::table('sensaciones_media',fn(Blueprint $table)=>$table->dropColumn(['poster_path','poster_mime']));
    }
};
