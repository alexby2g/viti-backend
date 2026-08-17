<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $catalog = DB::table('catalogo_aplicaciones')->where('clave', 'electrofrio')->first();
        if (!$catalog) return;

        DB::table('catalogo_aplicaciones')->where('id', $catalog->id)->update([
            'nombre' => 'Sistema de Gestión de Servicios de Aire Acondicionado',
            'descripcion' => 'Solución VITI configurable para administrar clientes, equipos, citas, diagnósticos, propuestas, órdenes de servicio, evidencias, pagos, garantías e historial técnico de negocios de aire acondicionado.',
            'updated_at' => now(),
        ]);

        DB::table('aplicaciones')
            ->where('catalogo_aplicacion_id', $catalog->id)
            ->whereIn('nombre', ['Electrofrío', 'Electrofrio'])
            ->update([
                'nombre' => 'Sistema de Gestión de Servicios de Aire Acondicionado',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $catalog = DB::table('catalogo_aplicaciones')->where('clave', 'electrofrio')->first();
        if (!$catalog) return;

        DB::table('catalogo_aplicaciones')->where('id', $catalog->id)->update([
            'nombre' => 'Electrofrío',
            'descripcion' => 'Aplicación VITI para citas, diagnóstico, órdenes de servicio, equipos, materiales, pagos, garantías e historial de Electrofrío.',
            'updated_at' => now(),
        ]);

        DB::table('aplicaciones')
            ->where('catalogo_aplicacion_id', $catalog->id)
            ->where('nombre', 'Sistema de Gestión de Servicios de Aire Acondicionado')
            ->update([
                'nombre' => 'Electrofrío',
                'updated_at' => now(),
            ]);
    }
};
