<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('servicio_tecnico_clientes')) {
            Schema::create('servicio_tecnico_clientes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->string('nombre', 180);
                $table->string('telefono', 30)->nullable();
                $table->string('whatsapp', 30)->nullable();
                $table->string('direccion', 255)->nullable();
                $table->text('observaciones')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();
                $table->index(['empresa_id','nombre']);
                $table->index(['empresa_id','telefono']);
            });
        }

        if (!Schema::hasTable('servicio_tecnico_equipos')) {
            Schema::create('servicio_tecnico_equipos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->foreignId('cliente_id')->constrained('servicio_tecnico_clientes')->restrictOnDelete();
                $table->string('tipo', 120)->default('Computadora');
                $table->string('marca', 120)->nullable();
                $table->string('modelo', 120)->nullable();
                $table->string('serie', 120)->nullable();
                $table->text('especificaciones')->nullable();
                $table->text('accesorios_recibidos')->nullable();
                $table->text('estado_recepcion')->nullable();
                $table->text('observaciones')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();
                $table->index(['empresa_id','cliente_id']);
                $table->index(['empresa_id','serie']);
            });
        }

        if (!Schema::hasTable('servicio_tecnico_tecnicos')) {
            Schema::create('servicio_tecnico_tecnicos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
                $table->string('nombre', 180);
                $table->string('telefono', 30)->nullable();
                $table->string('especialidad', 160)->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();
                $table->index(['empresa_id','activo']);
                $table->unique(['empresa_id','usuario_id']);
            });
        }

        if (!Schema::hasTable('servicio_tecnico_ordenes')) {
            Schema::create('servicio_tecnico_ordenes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->string('codigo', 40)->unique();
                $table->foreignId('cliente_id')->constrained('servicio_tecnico_clientes')->restrictOnDelete();
                $table->foreignId('equipo_id')->nullable()->constrained('servicio_tecnico_equipos')->nullOnDelete();
                $table->foreignId('tecnico_id')->nullable()->constrained('servicio_tecnico_tecnicos')->nullOnDelete();
                $table->date('fecha_recepcion');
                $table->date('fecha_programada')->nullable();
                $table->time('hora_programada')->nullable();
                $table->string('prioridad', 20)->default('normal');
                $table->string('estado', 40)->default('recibido');
                $table->string('decision_cliente', 20)->default('pendiente');
                $table->text('problema_reportado');
                $table->text('diagnostico')->nullable();
                $table->text('propuesta')->nullable();
                $table->text('motivo_rechazo')->nullable();
                $table->text('trabajo_realizado')->nullable();
                $table->text('recomendaciones')->nullable();
                $table->decimal('costo_servicio', 12, 2)->default(0);
                $table->decimal('descuento', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->unsignedInteger('garantia_dias')->default(0);
                $table->date('garantia_inicio')->nullable();
                $table->date('garantia_fin')->nullable();
                $table->text('condiciones_garantia')->nullable();
                $table->timestamp('decision_at')->nullable();
                $table->timestamp('finalizada_at')->nullable();
                $table->timestamps();
                $table->index(['empresa_id','estado']);
                $table->index(['empresa_id','fecha_programada']);
                $table->index(['empresa_id','cliente_id']);
            });
        }

        if (!Schema::hasTable('servicio_tecnico_pagos')) {
            Schema::create('servicio_tecnico_pagos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->foreignId('orden_id')->constrained('servicio_tecnico_ordenes')->cascadeOnDelete();
                $table->decimal('monto', 12, 2);
                $table->string('metodo', 40);
                $table->string('referencia', 160)->nullable();
                $table->string('estado', 30)->default('pagado');
                $table->timestamp('pagado_at');
                $table->timestamps();
                $table->index(['empresa_id','pagado_at']);
            });
        }

        if (!Schema::hasTable('servicio_tecnico_evidencias')) {
            Schema::create('servicio_tecnico_evidencias', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->foreignId('orden_id')->constrained('servicio_tecnico_ordenes')->cascadeOnDelete();
                $table->foreignId('subido_por')->nullable()->constrained('usuarios')->nullOnDelete();
                $table->string('etapa', 40)->default('recepcion');
                $table->string('nombre_original', 255);
                $table->string('ruta', 500);
                $table->string('mime', 120)->nullable();
                $table->unsignedBigInteger('tamano')->nullable();
                $table->text('descripcion')->nullable();
                $table->timestamps();
                $table->index(['empresa_id','orden_id']);
            });
        }

        $this->advanceSoporteVital();
    }

    private function advanceSoporteVital(): void
    {
        if (!Schema::hasTable('solicitudes_sistema') || !Schema::hasTable('proyectos') || !Schema::hasTable('aplicaciones')) return;
        $request = DB::table('solicitudes_sistema')->where('codigo','SOL-00007')->first();
        if (!$request) return;
        $project = DB::table('proyectos')->where('solicitud_id',$request->id)->first();
        if (!$project) return;

        $now = now();
        DB::table('proyectos')->where('id',$project->id)->update([
            'fase'=>'desarrollo',
            'estado'=>'activo',
            'progreso'=>max(80,(int)($project->progreso ?? 0)),
            'updated_at'=>$now,
        ]);

        $app = DB::table('aplicaciones')->where('proyecto_id',$project->id)->first();
        if ($app) {
            DB::table('aplicaciones')->where('id',$app->id)->update([
                'version'=>'0.8.0',
                'entorno'=>'beta',
                'estado'=>'en_pruebas',
                'acceso_cliente'=>false,
                'notas'=>'Avance funcional 80%. Operativos para pruebas internas: clientes, computadoras, técnicos, órdenes, agenda, pagos, garantías, historial y evidencias fotográficas privadas. Pendiente para entrega final: pruebas de aceptación con la empresa, reportes PDF finales, ajustes de experiencia móvil y habilitación controlada de beta.',
                'updated_at'=>$now,
            ]);
        }

        if (!Schema::hasTable('proyecto_avances')) return;
        $creator = DB::table('usuarios')->where('estado','activo')->whereIn('rol',['superadmin','administrador'])
            ->orderByRaw("CASE WHEN rol = 'superadmin' THEN 0 ELSE 1 END")->value('id');
        if (!$creator) return;

        $progress = [
            [60,'Persistencia del núcleo operativo','Clientes, computadoras y técnicos ya cuentan con almacenamiento aislado por empresa y validaciones para evitar cruces de información.'],
            [70,'Órdenes, agenda y control económico','Se habilitó el flujo funcional de recepción, diagnóstico, aprobación, reparación, pruebas, entrega, pagos parciales, saldo y garantía.'],
            [80,'Evidencias e historial listos para pruebas internas','La versión 0.8 incorpora fotografías privadas por orden, historial completo y métricas reales. El sistema entra en etapa de pruebas internas antes de habilitar la beta a la empresa.'],
        ];
        foreach ($progress as [$percent,$title,$description]) {
            $exists = DB::table('proyecto_avances')->where('proyecto_id',$project->id)->where('progreso',$percent)->exists();
            if ($exists) continue;
            DB::table('proyecto_avances')->insert([
                'proyecto_id'=>$project->id,
                'creado_por'=>$creator,
                'fase'=>'desarrollo',
                'titulo'=>$title,
                'descripcion'=>$description,
                'progreso'=>$percent,
                'visible_cliente'=>true,
                'created_at'=>$now,
                'updated_at'=>$now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('servicio_tecnico_evidencias');
        Schema::dropIfExists('servicio_tecnico_pagos');
        Schema::dropIfExists('servicio_tecnico_ordenes');
        Schema::dropIfExists('servicio_tecnico_tecnicos');
        Schema::dropIfExists('servicio_tecnico_equipos');
        Schema::dropIfExists('servicio_tecnico_clientes');
    }
};