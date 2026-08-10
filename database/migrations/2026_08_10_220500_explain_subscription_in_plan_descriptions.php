<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('planes_viti')) return;

        $descriptions = [
            'basico-1800' => 'Clientes, equipos, técnicos, agenda, órdenes, historial y buzón. Desarrollo referencial Bs 1.800 + suscripción Bs 35/mes. Incluye 14 días de prueba gratuita desde la habilitación de la beta.',
            'profesional-1950' => 'Agrega control de pagos y garantías para servicios técnicos. Desarrollo referencial Bs 1.950 + suscripción Bs 50/mes. Incluye 14 días de prueba gratuita desde la habilitación de la beta.',
            'empresa-2500' => 'Incluye todos los módulos técnicos, inventario, pagos, garantías y mayor capacidad operativa. Desarrollo referencial Bs 2.500 + suscripción Bs 75/mes. Incluye 14 días de prueba gratuita desde la habilitación de la beta.',
        ];

        foreach ($descriptions as $code => $description) {
            DB::table('planes_viti')->where('codigo', $code)->update(['descripcion'=>$description,'updated_at'=>now()]);
        }
    }

    public function down(): void
    {
        // Las descripciones comerciales se conservan.
    }
};
