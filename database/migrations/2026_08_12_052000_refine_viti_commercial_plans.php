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
                if (!Schema::hasColumn('planes_viti', 'precio_anual')) {
                    $table->decimal('precio_anual', 12, 2)->nullable()->after('precio_mensual');
                }
            });

            $now = now();
            $plans = [
                'basico-1800' => [
                    'nombre' => 'VITI Inicial',
                    'descripcion' => 'Para una microempresa que necesita organizar clientes, equipos, agenda, órdenes, técnicos, historial y mensajes. Incluye 1 aplicación VITI. La implementación cubre configuración inicial, puesta en marcha y capacitación básica; la suscripción cubre continuidad de plataforma, alojamiento, base de datos, respaldos y soporte ordinario.',
                    'precio_proyecto' => 1800,
                    'precio_mensual' => 89,
                    'precio_anual' => 890,
                    'dias_prueba' => 14,
                    'modulos' => ['inicio','agenda','ordenes','clientes','equipos','tecnicos','historial','buzon'],
                    'max_usuarios' => 3,
                    'max_aplicaciones' => 1,
                    'activo' => true,
                ],
                'profesional-1950' => [
                    'nombre' => 'VITI Profesional',
                    'descripcion' => 'Para servicios técnicos que además necesitan pagos, saldos, garantías, comprobantes y mayor control operativo. Incluye hasta 6 usuarios y 1 aplicación. La implementación cubre configuración inicial, puesta en marcha y capacitación; la suscripción mantiene la plataforma, alojamiento, base de datos, respaldos, actualizaciones generales y soporte ordinario.',
                    'precio_proyecto' => 2400,
                    'precio_mensual' => 129,
                    'precio_anual' => 1290,
                    'dias_prueba' => 14,
                    'modulos' => ['inicio','agenda','ordenes','clientes','equipos','tecnicos','pagos','garantias','historial','buzon'],
                    'max_usuarios' => 6,
                    'max_aplicaciones' => 1,
                    'activo' => true,
                ],
                'empresa-2500' => [
                    'nombre' => 'VITI Empresa',
                    'descripcion' => 'Para operaciones con mayor volumen que requieren inventario técnico, pagos, garantías, más usuarios y hasta 3 aplicaciones. Incluye hasta 15 usuarios. La implementación cubre configuración avanzada, puesta en marcha y capacitación; la suscripción mantiene infraestructura, base de datos, respaldos, actualizaciones generales y soporte ordinario.',
                    'precio_proyecto' => 3200,
                    'precio_mensual' => 189,
                    'precio_anual' => 1890,
                    'dias_prueba' => 14,
                    'modulos' => ['inicio','agenda','ordenes','clientes','equipos','tecnicos','inventario','pagos','garantias','historial','buzon'],
                    'max_usuarios' => 15,
                    'max_aplicaciones' => 3,
                    'activo' => true,
                ],
            ];

            foreach ($plans as $code => $data) {
                DB::table('planes_viti')->where('codigo', $code)->update([
                    ...$data,
                    'modulos' => json_encode($data['modulos']),
                    'updated_at' => $now,
                ]);
            }

            $custom = DB::table('planes_viti')->where('codigo', 'personalizado')->first();
            if ($custom) {
                DB::table('planes_viti')->where('codigo', 'personalizado')->update([
                    'nombre' => 'Cotización personalizada',
                    'descripcion' => 'Para varias sucursales o empresas, más de 15 usuarios, más de 3 aplicaciones, integraciones bancarias o de facturación, operación offline especial, aplicación móvil específica u otros requerimientos fuera de los planes estándar. AGR Studio revisa el alcance y cotiza implementación y suscripción antes de aprobar el proyecto.',
                    'precio_proyecto' => null,
                    'precio_mensual' => null,
                    'precio_anual' => null,
                    'dias_prueba' => 14,
                    'modulos' => json_encode([]),
                    'max_usuarios' => null,
                    'max_aplicaciones' => null,
                    'activo' => true,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('planes_viti')->insert([
                    'codigo' => 'personalizado',
                    'nombre' => 'Cotización personalizada',
                    'descripcion' => 'Para varias sucursales o empresas, más de 15 usuarios, más de 3 aplicaciones, integraciones bancarias o de facturación, operación offline especial, aplicación móvil específica u otros requerimientos fuera de los planes estándar. AGR Studio revisa el alcance y cotiza implementación y suscripción antes de aprobar el proyecto.',
                    'precio_proyecto' => null,
                    'precio_mensual' => null,
                    'precio_anual' => null,
                    'dias_prueba' => 14,
                    'modulos' => json_encode([]),
                    'max_usuarios' => null,
                    'max_aplicaciones' => null,
                    'activo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (Schema::hasTable('solicitudes_sistema')) {
            Schema::table('solicitudes_sistema', function (Blueprint $table): void {
                if (!Schema::hasColumn('solicitudes_sistema', 'frecuencia_suscripcion_preferida')) {
                    $table->string('frecuencia_suscripcion_preferida', 20)->nullable()->after('forma_pago_preferida');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('solicitudes_sistema') && Schema::hasColumn('solicitudes_sistema', 'frecuencia_suscripcion_preferida')) {
            Schema::table('solicitudes_sistema', fn (Blueprint $table) => $table->dropColumn('frecuencia_suscripcion_preferida'));
        }

        if (!Schema::hasTable('planes_viti')) return;

        DB::table('planes_viti')->where('codigo', 'basico-1800')->update([
            'nombre' => 'Plan Inicial', 'precio_proyecto' => 1800, 'precio_mensual' => 35, 'max_usuarios' => null,
        ]);
        DB::table('planes_viti')->where('codigo', 'profesional-1950')->update([
            'nombre' => 'Plan Profesional', 'precio_proyecto' => 1950, 'precio_mensual' => 50,
        ]);
        DB::table('planes_viti')->where('codigo', 'empresa-2500')->update([
            'nombre' => 'Plan Empresa', 'precio_proyecto' => 2500, 'precio_mensual' => 75,
        ]);
        DB::table('planes_viti')->where('codigo', 'personalizado')->update(['activo' => false]);

        Schema::table('planes_viti', function (Blueprint $table): void {
            if (Schema::hasColumn('planes_viti', 'precio_anual')) $table->dropColumn('precio_anual');
        });
    }
};