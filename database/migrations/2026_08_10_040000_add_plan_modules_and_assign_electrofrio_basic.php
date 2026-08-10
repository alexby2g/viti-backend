<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BASIC_MODULES = [
        'inicio',
        'agenda',
        'ordenes',
        'clientes',
        'equipos',
        'tecnicos',
        'historial',
        'buzon',
    ];

    public function up(): void
    {
        Schema::table('planes_viti', function (Blueprint $table): void {
            $table->decimal('precio_proyecto', 12, 2)->nullable()->after('descripcion');
            $table->json('modulos')->nullable()->after('precio_proyecto');
        });

        $now = now();
        DB::table('planes_viti')->updateOrInsert(
            ['codigo' => 'basico-1800'],
            [
                'nombre' => 'Plan Básico',
                'descripcion' => 'Gestión esencial para servicios técnicos: agenda, órdenes, clientes, equipos, técnicos, historial/reportes básicos y mensajes.',
                'precio_proyecto' => 1800,
                'modulos' => json_encode(self::BASIC_MODULES),
                'max_usuarios' => null,
                'max_aplicaciones' => 1,
                'activo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $planId = DB::table('planes_viti')->where('codigo', 'basico-1800')->value('id');
        DB::table('empresas')
            ->where('codigo', 'EMP-ELECTROFRIO')
            ->whereNull('deleted_at')
            ->update(['plan_viti_id' => $planId, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $personalizedId = DB::table('planes_viti')->where('codigo', 'personalizado')->value('id');
        DB::table('empresas')
            ->where('codigo', 'EMP-ELECTROFRIO')
            ->where('plan_viti_id', DB::table('planes_viti')->where('codigo', 'basico-1800')->value('id'))
            ->update(['plan_viti_id' => $personalizedId, 'updated_at' => now()]);

        DB::table('planes_viti')->where('codigo', 'basico-1800')->delete();

        Schema::table('planes_viti', function (Blueprint $table): void {
            $table->dropColumn(['precio_proyecto', 'modulos']);
        });
    }
};
