<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LocalDevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config('database.connections.'.$connection.'.database');
        $allowedLocalDatabases = ['viti_db', 'viti_pruebas_local_02'];

        if (!app()->environment('local') || !in_array($database, $allowedLocalDatabases, true)) {
            throw new \RuntimeException('La limpieza de VITI solo se permite en una base local autorizada.');
        }

        $beforeUsers = $this->userSnapshot();
        $deleted = [];

        DB::transaction(function () use (&$deleted, $beforeUsers): void {
            /*
             * VITI local debe iniciar como una plataforma vacía. No se crean
             * empresas, aplicaciones, proyectos, solicitudes ni clientes demo.
             *
             * Los módulos históricos permanecen en el código para no destruir
             * funcionalidad, pero no se provisionan ni se muestran por defecto.
             *
             * IMPORTANTE: la tabla usuarios NO se actualiza ni se elimina.
             */
            $tables = [
                // FitFamily
                'fitfamily_pagos','fitfamily_pedido_detalles','fitfamily_pedidos','fitfamily_carrito_items','fitfamily_carritos',
                'fitfamily_productos','fitfamily_categorias','fitfamily_configuracion',

                // Peluquería
                'peluqueria_combo_servicios','peluqueria_producto_movimientos','peluqueria_pagos','peluqueria_atenciones',
                'peluqueria_citas','peluqueria_productos','peluqueria_servicios','peluqueria_personal','peluqueria_clientes',

                // Servicio técnico genérico
                'servicio_tecnico_evidencias','servicio_tecnico_pagos','servicio_tecnico_ordenes','servicio_tecnico_equipos',
                'servicio_tecnico_tecnicos','servicio_tecnico_clientes',

                // Electrofrío / aire acondicionado (excepto perfiles enlazados a usuarios)
                'electrofrio_evidencias','electrofrio_fichas_tecnicas','electrofrio_orden_estados','electrofrio_orden_material',
                'electrofrio_pagos','electrofrio_ordenes','electrofrio_materiales','electrofrio_equipos','electrofrio_tecnicos',
                'electrofrio_configuraciones',

                // Comunicación y soporte
                'llamada_senales','llamadas','atencion_sesiones','chat_presencias','mensajes','conversaciones','mantenimientos',

                // SaaS / entrega
                'suscripcion_pagos','suscripciones','aplicacion_usuario','alertas_saas',

                // Proyecto
                'proyecto_dominios','proyecto_ambientes','proyecto_repositorios','proyecto_miembros','proyecto_pagos','proyecto_avances',

                // Solicitudes, archivos y auditoría
                'solicitud_respuestas','invitaciones_clientes','archivos','auditoria','system_backups',
            ];

            foreach ($tables as $table) {
                $deleted[$table] = $this->deleteAll($table);
            }

            if (Schema::hasTable('aplicaciones')) {
                if (Schema::hasColumn('aplicaciones', 'aplicacion_origen_id')) {
                    DB::table('aplicaciones')->update(['aplicacion_origen_id' => null]);
                }
                $deleted['aplicaciones'] = DB::table('aplicaciones')->delete();
            }

            $deleted['catalogo_aplicaciones'] = $this->deleteAll('catalogo_aplicaciones');
            $deleted['proyectos'] = $this->deleteAll('proyectos');
            $deleted['solicitudes_sistema'] = $this->deleteAll('solicitudes_sistema');
            $deleted['empresa_usuario'] = $this->deleteAll('empresa_usuario');
            $deleted['empresas'] = $this->deleteAll('empresas');

            // Conserva perfiles de cliente que estén vinculados a un usuario.
            if (Schema::hasTable('clientes')) {
                $linkedClientIds = Schema::hasColumn('usuarios', 'cliente_id')
                    ? DB::table('usuarios')->whereNotNull('cliente_id')->pluck('cliente_id')->filter()->values()->all()
                    : [];
                $query = DB::table('clientes');
                if ($linkedClientIds) $query->whereNotIn('id', $linkedClientIds);
                $deleted['clientes_no_vinculados'] = $query->delete();
            }

            // Igual para el perfil legado de cliente Electrofrío si algún usuario aún lo referencia.
            if (Schema::hasTable('electrofrio_clientes')) {
                $linkedIds = Schema::hasColumn('usuarios', 'electrofrio_cliente_id')
                    ? DB::table('usuarios')->whereNotNull('electrofrio_cliente_id')->pluck('electrofrio_cliente_id')->filter()->values()->all()
                    : [];
                $query = DB::table('electrofrio_clientes');
                if ($linkedIds) $query->whereNotIn('id', $linkedIds);
                $deleted['electrofrio_clientes_no_vinculados'] = $query->delete();
            }

            if ($this->userSnapshot() !== $beforeUsers) {
                throw new \RuntimeException('Limpieza cancelada: se detectó un cambio en usuarios.');
            }
        });

        $total = array_sum($deleted);
        $remaining = collect(['empresas','solicitudes_sistema','proyectos','aplicaciones','catalogo_aplicaciones'])
            ->mapWithKeys(fn (string $table) => [$table => Schema::hasTable($table) ? DB::table($table)->count() : 0]);

        $this->command?->info("VITI local limpio: {$total} registros operativos eliminados.");
        $this->command?->info('Usuarios preservados sin modificaciones. Planes, cuestionarios y configuración de plataforma se conservaron.');
        $this->command?->info('Verificación: '.$remaining->map(fn ($count,$table) => $table.'='.$count)->implode(' | '));
    }

    private function deleteAll(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->delete() : 0;
    }

    private function userSnapshot(): string
    {
        if (!Schema::hasTable('usuarios')) return '[]';

        return DB::table('usuarios')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row) => (array) $row)
            ->values()
            ->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
