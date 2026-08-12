<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones_plataforma', function (Blueprint $table): void {
            $table->id();
            $table->string('studio_name', 120)->default('AGR Studio');
            $table->string('product_name', 80)->default('VITI');
            $table->string('product_meaning', 180)->default('Visión Integral, Tecnología e Innovación');
            $table->string('tagline', 220)->default('Plataforma de proyectos y soluciones digitales');
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 7)->default('#1565C0');
            $table->string('secondary_color', 7)->default('#43A047');
            $table->string('accent_color', 7)->default('#FB8C00');
            $table->string('dark_color', 7)->default('#071C3B');
            $table->string('drawer_color', 7)->default('#092B55');
            $table->boolean('guide_enabled')->default(true);
            $table->string('guide_position', 30)->default('right-center');
            $table->timestamps();
        });

        DB::table('configuraciones_plataforma')->insert([
            'studio_name' => 'AGR Studio',
            'product_name' => 'VITI',
            'product_meaning' => 'Visión Integral, Tecnología e Innovación',
            'tagline' => 'Plataforma de proyectos y soluciones digitales',
            'primary_color' => '#1565C0',
            'secondary_color' => '#43A047',
            'accent_color' => '#FB8C00',
            'dark_color' => '#071C3B',
            'drawer_color' => '#092B55',
            'guide_enabled' => true,
            'guide_position' => 'right-center',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones_plataforma');
    }
};
