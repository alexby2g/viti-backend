<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $planId = DB::table('planes_viti')->where('codigo', 'personalizado')->value('id');

            $empresa = DB::table('empresas')
                ->where('codigo', 'EMP-ELECTROFRIO')
                ->orWhereRaw('LOWER(nombre_comercial) = ?', ['electrofrío'])
                ->orWhereRaw('LOWER(nombre_comercial) = ?', ['electrofrio'])
                ->orderBy('id')
                ->first();

            if ($empresa) {
                DB::table('empresas')->where('id', $empresa->id)->update([
                    'plan_viti_id' => $empresa->plan_viti_id ?: $planId,
                    'nombre_comercial' => 'Electrofrío',
                    'actividad' => $empresa->actividad ?: 'Servicios técnicos de aire acondicionado y refrigeración',
                    'estado' => 'activo',
                    'moneda' => 'BOB',
                    'zona_horaria' => 'America/La_Paz',
                    'deleted_at' => null,
                    'updated_at' => $now,
                ]);
                $empresaId = (int)$empresa->id;
            } else {
                $empresaId = (int)DB::table('empresas')->insertGetId([
                    'cliente_id' => null,
                    'plan_viti_id' => $planId,
                    'codigo' => 'EMP-ELECTROFRIO',
                    'nombre_comercial' => 'Electrofrío',
                    'razon_social' => null,
                    'actividad' => 'Servicios técnicos de aire acondicionado y refrigeración',
                    'telefono' => null,
                    'whatsapp' => null,
                    'ciudad' => 'Trinidad',
                    'direccion' => null,
                    'logo_path' => null,
                    'observaciones' => 'Empresa propia integrada y administrada desde VITI.',
                    'estado' => 'activo',
                    'moneda' => 'BOB',
                    'zona_horaria' => 'America/La_Paz',
                    'configuracion' => json_encode(['datos_iniciales' => 'vacios']),
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
            }

            DB::table('catalogo_aplicaciones')->updateOrInsert(
                ['clave' => 'electrofrio'],
                [
                    'nombre' => 'Electrofrío',
                    'descripcion' => 'Gestión de clientes, equipos, servicios técnicos, materiales, pagos e historial de Electrofrío.',
                    'icono' => 'ac_unit',
                    'tipo' => 'web',
                    'ruta_base' => null,
                    'activo' => true,
                    'solicitable' => false,
                    'orden' => 20,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $catalogoId = DB::table('catalogo_aplicaciones')->where('clave', 'electrofrio')->value('id');

            $application = DB::table('aplicaciones')
                ->where('slug', 'electrofrio-viti')
                ->orWhere(function ($query) use ($empresaId): void {
                    $query->where('empresa_id', $empresaId)
                        ->where(function ($nameQuery): void {
                            $nameQuery->whereRaw('LOWER(nombre) = ?', ['electrofrío'])
                                ->orWhereRaw('LOWER(nombre) = ?', ['electrofrio']);
                        });
                })
                ->orderBy('id')
                ->first();

            $applicationData = [
                'empresa_id' => $empresaId,
                'catalogo_aplicacion_id' => $catalogoId,
                'nombre' => 'Electrofrío',
                'version' => '1.0',
                'tipo' => 'web',
                'tecnologias' => 'Laravel, Quasar',
                'entorno' => 'produccion',
                'estado' => 'activo',
                'acceso_cliente' => true,
                'entregado_at' => $now,
                'provisionado_at' => $now,
                'url' => 'https://electrofrio-frontend.vercel.app',
                'url_administracion' => 'https://electrofrio-frontend.vercel.app',
                'repositorio_url' => 'https://github.com/alexby2g/electrofrio-frontend',
                'proveedor_hosting' => 'Vercel + Render',
                'notas' => 'Integración propia de Electrofrío. Los datos operativos comienzan vacíos.',
                'publicado_at' => $now,
                'deleted_at' => null,
                'updated_at' => $now,
            ];

            if ($application) {
                DB::table('aplicaciones')->where('id', $application->id)->update($applicationData);
            } else {
                DB::table('aplicaciones')->insert($applicationData + [
                    'proyecto_id' => null,
                    'slug' => 'electrofrio-viti',
                    'created_at' => $now,
                ]);
            }

            $primaryAdminId = DB::table('usuarios')
                ->where('rol', 'superadmin')
                ->orderBy('id')
                ->value('id');

            if ($primaryAdminId) {
                DB::table('empresa_usuario')->updateOrInsert(
                    ['empresa_id' => $empresaId, 'usuario_id' => $primaryAdminId],
                    [
                        'rol_negocio' => 'administrador',
                        'permisos' => null,
                        'activo' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        });
    }

    public function down(): void
    {
        // Integración de datos intencionalmente conservada para evitar borrar
        // una empresa o aplicación que ya haya comenzado a utilizarse.
    }
};
