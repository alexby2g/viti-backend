<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('planes_viti')) {
            Schema::table('planes_viti', function (Blueprint $table): void {
                if (!Schema::hasColumn('planes_viti', 'precio_mensual')) {
                    $table->decimal('precio_mensual', 12, 2)->nullable()->after('precio_proyecto');
                }
                if (!Schema::hasColumn('planes_viti', 'dias_prueba')) {
                    $table->unsignedSmallInteger('dias_prueba')->default(14)->after('precio_mensual');
                }
            });

            $now = now();
            $plans = [
                [
                    'codigo' => 'basico-1800',
                    'nombre' => 'Plan Inicial',
                    'descripcion' => 'Para negocios que necesitan organizar clientes, equipos, técnicos, agenda, órdenes e historial con una aplicación VITI.',
                    'precio_proyecto' => 1800,
                    'precio_mensual' => 35,
                    'dias_prueba' => 14,
                    'modulos' => ['inicio','agenda','ordenes','clientes','equipos','tecnicos','historial','buzon'],
                    'max_usuarios' => null,
                    'max_aplicaciones' => 1,
                ],
                [
                    'codigo' => 'profesional-1950',
                    'nombre' => 'Plan Profesional',
                    'descripcion' => 'Para servicios técnicos que además necesitan control de pagos, garantías e historial completo. Incluye hasta 6 usuarios.',
                    'precio_proyecto' => 1950,
                    'precio_mensual' => 50,
                    'dias_prueba' => 14,
                    'modulos' => ['inicio','agenda','ordenes','clientes','equipos','tecnicos','pagos','garantias','historial','buzon'],
                    'max_usuarios' => 6,
                    'max_aplicaciones' => 1,
                ],
                [
                    'codigo' => 'empresa-2500',
                    'nombre' => 'Plan Empresa',
                    'descripcion' => 'Para negocios con mayor operación: incluye todos los módulos técnicos, inventario, pagos, garantías y hasta 3 aplicaciones.',
                    'precio_proyecto' => 2500,
                    'precio_mensual' => 75,
                    'dias_prueba' => 14,
                    'modulos' => ['inicio','agenda','ordenes','clientes','equipos','tecnicos','inventario','pagos','garantias','historial','buzon'],
                    'max_usuarios' => 15,
                    'max_aplicaciones' => 3,
                ],
            ];

            foreach ($plans as $plan) {
                $existing = DB::table('planes_viti')->where('codigo', $plan['codigo'])->first();
                DB::table('planes_viti')->updateOrInsert(
                    ['codigo' => $plan['codigo']],
                    [
                        'nombre' => $plan['nombre'],
                        'descripcion' => $plan['descripcion'],
                        'precio_proyecto' => $plan['precio_proyecto'],
                        'precio_mensual' => $plan['precio_mensual'],
                        'dias_prueba' => $plan['dias_prueba'],
                        'modulos' => json_encode($plan['modulos']),
                        'max_usuarios' => $plan['max_usuarios'],
                        'max_aplicaciones' => $plan['max_aplicaciones'],
                        'activo' => true,
                        'updated_at' => $now,
                        'created_at' => $existing?->created_at ?? $now,
                    ]
                );
            }

            DB::table('planes_viti')->where('codigo', 'personalizado')->update(['activo' => false, 'updated_at' => $now]);
        }

        if (Schema::hasTable('proyectos')) {
            Schema::table('proyectos', function (Blueprint $table): void {
                if (!Schema::hasColumn('proyectos', 'precio_estimado')) {
                    $table->decimal('precio_estimado', 12, 2)->nullable()->after('observaciones');
                }
                if (!Schema::hasColumn('proyectos', 'complejidad')) {
                    $table->string('complejidad', 40)->nullable()->after('precio_estimado');
                }
                if (!Schema::hasColumn('proyectos', 'dias_estimados')) {
                    $table->unsignedSmallInteger('dias_estimados')->nullable()->after('complejidad');
                }
            });
        }

        if (Schema::hasTable('solicitud_respuestas')) {
            Schema::table('solicitud_respuestas', function (Blueprint $table): void {
                if (!Schema::hasColumn('solicitud_respuestas', 'origen')) {
                    $table->string('origen', 30)->default('cliente')->after('respuesta_json');
                }
            });
        }

        if (Schema::hasTable('suscripciones')) {
            Schema::table('suscripciones', function (Blueprint $table): void {
                if (!Schema::hasColumn('suscripciones', 'prueba_hasta')) {
                    $table->date('prueba_hasta')->nullable()->after('fecha_inicio');
                }
                if (!Schema::hasColumn('suscripciones', 'primer_cobro_monto')) {
                    $table->decimal('primer_cobro_monto', 12, 2)->nullable()->after('prueba_hasta');
                }
                if (!Schema::hasColumn('suscripciones', 'primer_cobro_desde')) {
                    $table->date('primer_cobro_desde')->nullable()->after('primer_cobro_monto');
                }
                if (!Schema::hasColumn('suscripciones', 'primer_cobro_hasta')) {
                    $table->date('primer_cobro_hasta')->nullable()->after('primer_cobro_desde');
                }
                if (!Schema::hasColumn('suscripciones', 'primer_cobro_pagado')) {
                    $table->boolean('primer_cobro_pagado')->default(false)->after('primer_cobro_hasta');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('suscripciones')) {
            Schema::table('suscripciones', function (Blueprint $table): void {
                foreach (['prueba_hasta','primer_cobro_monto','primer_cobro_desde','primer_cobro_hasta','primer_cobro_pagado'] as $column) {
                    if (Schema::hasColumn('suscripciones', $column)) $table->dropColumn($column);
                }
            });
        }
        if (Schema::hasTable('solicitud_respuestas') && Schema::hasColumn('solicitud_respuestas', 'origen')) {
            Schema::table('solicitud_respuestas', fn (Blueprint $table) => $table->dropColumn('origen'));
        }
        if (Schema::hasTable('proyectos')) {
            Schema::table('proyectos', function (Blueprint $table): void {
                foreach (['precio_estimado','complejidad','dias_estimados'] as $column) {
                    if (Schema::hasColumn('proyectos', $column)) $table->dropColumn($column);
                }
            });
        }
        if (Schema::hasTable('planes_viti')) {
            Schema::table('planes_viti', function (Blueprint $table): void {
                foreach (['precio_mensual','dias_prueba'] as $column) {
                    if (Schema::hasColumn('planes_viti', $column)) $table->dropColumn($column);
                }
            });
        }
    }
};
