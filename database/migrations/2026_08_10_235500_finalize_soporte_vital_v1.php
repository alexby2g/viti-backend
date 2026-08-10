<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('solicitudes_sistema') || !Schema::hasTable('proyectos') || !Schema::hasTable('aplicaciones')) return;

        $solicitud = DB::table('solicitudes_sistema')->where('codigo','SOL-00007')->first();
        if (!$solicitud) return;

        $proyecto = DB::table('proyectos')->where('solicitud_id',$solicitud->id)->first();
        if (!$proyecto) return;

        $now = now();
        DB::table('proyectos')->where('id',$proyecto->id)->update([
            'fase'=>'finalizado',
            'estado'=>'activo',
            'progreso'=>100,
            'updated_at'=>$now,
        ]);

        $aplicacion = DB::table('aplicaciones')->where('proyecto_id',$proyecto->id)->first();
        if ($aplicacion) {
            DB::table('aplicaciones')->where('id',$aplicacion->id)->update([
                'version'=>'1.0.0',
                'entorno'=>'produccion',
                'estado'=>'activo',
                'acceso_cliente'=>false,
                'entregado_at'=>null,
                'publicado_at'=>null,
                'notas'=>'V1.0 terminada técnicamente. Operativos: clientes, computadoras, técnicos, órdenes, agenda, pagos, garantías, historial, evidencias privadas y reportes PDF. El acceso de la empresa permanece deshabilitado hasta que AGR Studio realice la entrega de forma explícita; la prueba gratuita y la suscripción todavía no comienzan.',
                'updated_at'=>$now,
            ]);
        }

        if (!Schema::hasTable('proyecto_avances') || !Schema::hasTable('usuarios')) return;
        $creator = DB::table('usuarios')->where('estado','activo')->whereIn('rol',['superadmin','administrador'])
            ->orderByRaw("CASE WHEN rol = 'superadmin' THEN 0 ELSE 1 END")
            ->value('id');
        if (!$creator) return;

        $avances = [
            [90,'Reportes, seguridad y preparación de entrega','Se cerraron reportes PDF, protección del historial, validación estricta del flujo de reparación, permisos por rol y rutas separadas para administración y futura operación de la empresa.'],
            [100,'V1.0 terminada técnicamente','Soporte Vital PC queda finalizado al 100% a nivel técnico. La aplicación está preparada para entrega, pero el acceso de la empresa permanece deshabilitado hasta que AGR Studio decida habilitarlo explícitamente.'],
        ];

        foreach ($avances as [$progreso,$titulo,$descripcion]) {
            $exists = DB::table('proyecto_avances')->where('proyecto_id',$proyecto->id)->where('progreso',$progreso)->exists();
            if ($exists) continue;
            DB::table('proyecto_avances')->insert([
                'proyecto_id'=>$proyecto->id,
                'creado_por'=>$creator,
                'fase'=>'finalizado',
                'titulo'=>$titulo,
                'descripcion'=>$descripcion,
                'progreso'=>$progreso,
                'visible_cliente'=>true,
                'created_at'=>$now,
                'updated_at'=>$now,
            ]);
        }
    }

    public function down(): void
    {
        // No se revierte automáticamente el estado comercial ni de entrega de una aplicación terminada.
    }
};