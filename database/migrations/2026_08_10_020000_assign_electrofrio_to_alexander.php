<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const USERNAME = 'alexander_electrofrio';
    private const PASSWORD_HASH = '$2b$12$/Mz/mmtZ0XBJL333YA1LFOgDJcITft68GdSeIfl6x84U22/uS5ZA6';

    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->string('telefono', 30)->nullable()->change();
        });

        DB::transaction(function (): void {
            $now = now();
            $user = DB::table('usuarios')->where('usuario', self::USERNAME)->first();
            $clienteId = $user?->cliente_id;

            if (!$clienteId) {
                $clienteId = DB::table('clientes')->insertGetId([
                    'nombre' => 'Alexander Omar Rivera',
                    'telefono' => null,
                    'whatsapp' => null,
                    'documento' => null,
                    'ci_expedido' => null,
                    'ciudad' => 'Trinidad',
                    'direccion' => null,
                    'foto_path' => null,
                    'foto_verificada' => false,
                    'observaciones' => 'Propietario de Electrofrío dentro de VITI.',
                    'estado' => 'activo',
                    'canal_origen' => 'viti',
                    'perfil_completo_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
            }

            if ($user) {
                DB::table('usuarios')->where('id', $user->id)->update([
                    'cliente_id' => $clienteId,
                    'nombre' => 'Alexander',
                    'apellido' => 'Omar Rivera',
                    'telefono' => null,
                    'password' => self::PASSWORD_HASH,
                    'rol' => 'cliente',
                    'estado' => 'activo',
                    'updated_at' => $now,
                ]);
                $userId = (int) $user->id;
            } else {
                $userId = (int) DB::table('usuarios')->insertGetId([
                    'cliente_id' => $clienteId,
                    'nombre' => 'Alexander',
                    'apellido' => 'Omar Rivera',
                    'usuario' => self::USERNAME,
                    'telefono' => null,
                    'password' => self::PASSWORD_HASH,
                    'rol' => 'cliente',
                    'estado' => 'activo',
                    'ultimo_acceso' => null,
                    'remember_token' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $empresa = DB::table('empresas')
                ->where('codigo', 'EMP-ELECTROFRIO')
                ->orWhereRaw('LOWER(nombre_comercial) = ?', ['electrofrío'])
                ->orWhereRaw('LOWER(nombre_comercial) = ?', ['electrofrio'])
                ->orderBy('id')
                ->first();

            abort_unless($empresa, 500, 'No se encontró la empresa Electrofrío que debe asignarse.');

            DB::table('empresas')->where('id', $empresa->id)->update([
                'cliente_id' => $clienteId,
                'nombre_comercial' => 'Electrofrío',
                'actividad' => 'Servicios técnicos de aire acondicionado y refrigeración',
                'ciudad' => 'Trinidad',
                'estado' => 'activo',
                'configuracion' => json_encode([
                    'datos_iniciales' => 'vacios',
                    'propietario' => self::USERNAME,
                    'aplicacion_nativa' => true,
                ]),
                'updated_at' => $now,
                'deleted_at' => null,
            ]);

            DB::table('empresa_usuario')
                ->where('empresa_id', $empresa->id)
                ->where('usuario_id', '<>', $userId)
                ->delete();

            DB::table('empresa_usuario')->updateOrInsert(
                ['empresa_id' => $empresa->id, 'usuario_id' => $userId],
                [
                    'rol_negocio' => 'propietario',
                    'permisos' => null,
                    'activo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            DB::table('catalogo_aplicaciones')->where('clave', 'electrofrio')->update([
                'ruta_base' => '/mi-apps/electrofrio/inicio',
                'activo' => true,
                'solicitable' => false,
                'updated_at' => $now,
            ]);

            DB::table('aplicaciones')
                ->where('empresa_id', $empresa->id)
                ->where(function ($query): void {
                    $query->where('slug', 'electrofrio-viti')
                        ->orWhereRaw('LOWER(nombre) = ?', ['electrofrío'])
                        ->orWhereRaw('LOWER(nombre) = ?', ['electrofrio']);
                })
                ->update([
                    'entorno' => 'produccion',
                    'estado' => 'activo',
                    'acceso_cliente' => true,
                    'url' => null,
                    'url_administracion' => null,
                    'repositorio_url' => null,
                    'entregado_at' => $now,
                    'provisionado_at' => $now,
                    'notas' => 'Aplicación nativa de VITI, propiedad del usuario Alexander Omar Rivera. Datos operativos vacíos y aislados.',
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);

            $primaryAdminId = DB::table('usuarios')
                ->where('rol', 'superadmin')
                ->where('estado', 'activo')
                ->orderBy('id')
                ->value('id');

            if (!DB::table('conversaciones')->where('cliente_id', $clienteId)->where('canal_principal', true)->exists()) {
                DB::table('conversaciones')->insert([
                    'cliente_id' => $clienteId,
                    'responsable_usuario_id' => $primaryAdminId,
                    'solicitud_id' => null,
                    'proyecto_id' => null,
                    'asunto' => 'Atención VITI',
                    'estado' => 'abierta',
                    'canal_principal' => true,
                    'ultimo_mensaje_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // La cuenta y la propiedad se conservan para no borrar una empresa que ya esté en uso.
    }
};
