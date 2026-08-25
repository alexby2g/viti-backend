<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            // Retira la aplicación de Peluquería del catálogo de VITI y limpia
            // únicamente la demostración que creó el seeder anterior.
            $demoEmpresaIds = DB::table('empresas')
                ->whereRaw('LOWER(nombre_comercial) = ?', ['salón bella viti demo'])
                ->pluck('id');

            if ($demoEmpresaIds->isNotEmpty()) {
                DB::table('empresa_usuario')->whereIn('empresa_id', $demoEmpresaIds)->delete();
                DB::table('aplicacion_usuario')->whereIn(
                    'aplicacion_id',
                    DB::table('aplicaciones')->whereIn('empresa_id', $demoEmpresaIds)->pluck('id')
                )->delete();
                DB::table('aplicaciones')->whereIn('empresa_id', $demoEmpresaIds)->delete();
                DB::table('proyectos')->whereIn('empresa_id', $demoEmpresaIds)->delete();
                DB::table('solicitudes_sistema')->whereIn('empresa_id', $demoEmpresaIds)->delete();
                DB::table('empresas')->whereIn('id', $demoEmpresaIds)->delete();
            }

            DB::table('catalogo_aplicaciones')->where('clave', 'peluqueria')->delete();

            // Electrofrío queda como una aplicación/empresa base sin dueño ni usuario.
            // Se conserva la empresa y su estructura operativa para poder asignarle
            // posteriormente un cliente y/o usuario desde el flujo administrativo.
            $electro = DB::table('empresas')
                ->where(function ($query): void {
                    $query->where('codigo', 'EMP-ELECTROFRIO')
                        ->orWhereRaw('LOWER(nombre_comercial) = ?', ['electrofrío'])
                        ->orWhereRaw('LOWER(nombre_comercial) = ?', ['electrofrio']);
                })
                ->orderBy('id')
                ->first();

            if ($electro) {
                DB::table('empresa_usuario')->where('empresa_id', $electro->id)->delete();

                DB::table('empresas')->where('id', $electro->id)->update([
                    'cliente_id' => null,
                    'nombre_comercial' => 'Electrofrío',
                    'actividad' => 'Servicios técnicos de aire acondicionado y refrigeración',
                    'ciudad' => 'Trinidad',
                    'estado' => 'activo',
                    'configuracion' => json_encode([
                        'datos_iniciales' => 'vacios',
                        'aplicacion_nativa' => true,
                        'asignacion_pendiente' => true,
                    ]),
                    'updated_at' => $now,
                ]);

                DB::table('aplicaciones')
                    ->where('empresa_id', $electro->id)
                    ->where(function ($query): void {
                        $query->where('slug', 'electrofrio-viti')
                            ->orWhereRaw('LOWER(nombre) = ?', ['electrofrío'])
                            ->orWhereRaw('LOWER(nombre) = ?', ['electrofrio']);
                    })
                    ->update([
                        'entorno' => 'produccion',
                        'estado' => 'activo',
                        'acceso_cliente' => false,
                        'notas' => 'Aplicación nativa de VITI para Electrofrío. Empresa creada sin usuario ni dueño; lista para asignación posterior.',
                        'updated_at' => $now,
                    ]);
            }

            // La cuenta creada exclusivamente para la asignación inicial deja de existir.
            $electroUser = DB::table('usuarios')->where('usuario', 'alexander_electrofrio')->first();
            if ($electroUser) {
                DB::table('empresa_usuario')->where('usuario_id', $electroUser->id)->delete();
                DB::table('aplicacion_usuario')->where('usuario_id', $electroUser->id)->delete();
                DB::table('usuarios')->where('id', $electroUser->id)->delete();
            }

            // El cliente técnico creado únicamente como propietario inicial queda
            // oculto del negocio y sin asociación comercial.
            if ($electroUser?->cliente_id) {
                DB::table('clientes')->where('id', $electroUser->cliente_id)->update([
                    'deleted_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Deliberadamente no recrea propietarios ni la aplicación Peluquería.
    }
};
