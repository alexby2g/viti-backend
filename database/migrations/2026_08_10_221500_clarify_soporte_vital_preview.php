<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('solicitudes_sistema') || !Schema::hasTable('proyectos')) return;
        $request = DB::table('solicitudes_sistema')->where('codigo', 'SOL-00007')->first();
        if (!$request) return;
        $project = DB::table('proyectos')->where('solicitud_id', $request->id)->first();
        if (!$project) return;

        if (Schema::hasTable('aplicaciones')) {
            DB::table('aplicaciones')->where('proyecto_id', $project->id)->update([
                'notas' => 'Avance 50%. Prototipo navegable del núcleo preparado para revisión: clientes, computadoras/equipos, técnicos, órdenes y agenda. En esta etapa la interfaz permite validar el flujo y la estructura; la persistencia final, fotografías, pagos completos, reportes, pruebas de aceptación y versión móvil continúan en desarrollo.',
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('proyecto_avances')) {
            DB::table('proyecto_avances')
                ->where('proyecto_id', $project->id)
                ->where('progreso', 50)
                ->update([
                    'titulo' => 'Prototipo navegable del núcleo listo para revisión',
                    'descripcion' => 'La versión 0.5 permite revisar la estructura y navegación de clientes, computadoras/equipos, técnicos, órdenes de servicio y agenda sin presentarla todavía como entrega final. La persistencia definitiva de estos módulos, fotografías, pagos completos, reportes, validación y versión móvil continúan en desarrollo.',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Conserva el historial del proyecto.
    }
};
