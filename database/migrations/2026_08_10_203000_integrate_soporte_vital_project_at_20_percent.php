<?php

use App\Support\Code;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('solicitudes_sistema') || !Schema::hasTable('proyectos')) {
            return;
        }

        $solicitud = DB::table('solicitudes_sistema')
            ->where('codigo', 'SOL-00007')
            ->first();

        if (!$solicitud || !$solicitud->empresa_id || !$solicitud->cliente_id) {
            return;
        }

        $now = now();
        $proyecto = DB::table('proyectos')
            ->where('solicitud_id', $solicitud->id)
            ->first();

        if (!$proyecto) {
            $responsableId = Schema::hasTable('usuarios')
                ? DB::table('usuarios')
                    ->where('estado', 'activo')
                    ->whereIn('rol', ['superadmin', 'administrador'])
                    ->orderByRaw("CASE WHEN rol = 'superadmin' THEN 0 ELSE 1 END")
                    ->value('id')
                : null;

            $proyectoId = DB::table('proyectos')->insertGetId([
                'solicitud_id' => $solicitud->id,
                'empresa_id' => $solicitud->empresa_id,
                'cliente_id' => $solicitud->cliente_id,
                'responsable_id' => $responsableId,
                'codigo' => Code::next('proyectos', 'PRO'),
                'nombre' => 'Soporte Vital PC · Sistema de gestión técnica',
                'descripcion' => 'Implementación del sistema solicitado para gestionar clientes, computadoras/equipos, servicios técnicos, personal, agenda, pagos, historial, fotografías y reportes.',
                'fase' => 'analisis',
                'estado' => 'activo',
                'progreso' => 20,
                'fecha_inicio' => $now->toDateString(),
                'fecha_beta' => null,
                'fecha_entrega' => null,
                'repositorio_url' => null,
                'produccion_url' => null,
                'observaciones' => 'Proyecto inicializado desde SOL-00007. El 20% representa levantamiento revisado y alcance/estructura inicial definidos. Las fechas beta y de entrega se establecerán después de la estimación formal de complejidad y pruebas.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $proyecto = DB::table('proyectos')->where('id', $proyectoId)->first();
        } else {
            $updates = ['updated_at' => $now];
            if ((int) ($proyecto->progreso ?? 0) < 20) {
                $updates['progreso'] = 20;
            }
            if (in_array($proyecto->fase, [null, 'levantamiento', 'analisis'], true)) {
                $updates['fase'] = 'analisis';
            }
            if (in_array($proyecto->estado, [null, 'activo'], true)) {
                $updates['estado'] = 'activo';
            }
            DB::table('proyectos')->where('id', $proyecto->id)->update($updates);
            $proyecto = DB::table('proyectos')->where('id', $proyecto->id)->first();
        }

        DB::table('solicitudes_sistema')
            ->where('id', $solicitud->id)
            ->update([
                'estado' => 'convertida',
                'aprobado_at' => $solicitud->aprobado_at ?: $now,
                'updated_at' => $now,
            ]);

        if (!Schema::hasTable('proyecto_avances') || !$proyecto) {
            return;
        }

        $creadoPor = DB::table('usuarios')
            ->where('estado', 'activo')
            ->whereIn('rol', ['superadmin', 'administrador'])
            ->orderByRaw("CASE WHEN rol = 'superadmin' THEN 0 ELSE 1 END")
            ->value('id');

        if (!$creadoPor) {
            return;
        }

        $this->insertAdvanceIfMissing(
            (int) $proyecto->id,
            (int) $creadoPor,
            'Solicitud y necesidades revisadas',
            'Se revisó la solicitud SOL-00007 y se consolidaron las necesidades principales: clientes, equipos, servicios técnicos, personal, agenda, pagos, historial, fotografías y reportes.',
            10,
            $now
        );

        $this->insertAdvanceIfMissing(
            (int) $proyecto->id,
            (int) $creadoPor,
            'Alcance y estructura inicial definidos',
            'Se definió la estructura inicial del sistema y el flujo general de atención técnica. El siguiente hito es construir los módulos base de clientes, equipos, técnicos y órdenes de servicio antes de habilitar una vista previa.',
            20,
            $now
        );
    }

    private function insertAdvanceIfMissing(
        int $proyectoId,
        int $creadoPor,
        string $titulo,
        string $descripcion,
        int $progreso,
        $now
    ): void {
        $exists = DB::table('proyecto_avances')
            ->where('proyecto_id', $proyectoId)
            ->where('titulo', $titulo)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('proyecto_avances')->insert([
            'proyecto_id' => $proyectoId,
            'creado_por' => $creadoPor,
            'fase' => 'analisis',
            'area' => 'analisis',
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'progreso' => $progreso,
            'visible_cliente' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Migración de integración de datos reales. No se elimina automáticamente
        // el proyecto ni sus avances para evitar pérdida de historial del cliente.
    }
};
