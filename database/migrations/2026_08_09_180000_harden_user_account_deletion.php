<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('usuarios', 'correo')) {
            Schema::table('usuarios', function (Blueprint $table): void {
                $table->string('correo', 160)->nullable()->unique()->after('telefono');
            });
        }

        DB::table('usuarios')->update(['usuario' => DB::raw('LOWER(usuario)')]);
        DB::statement('CREATE UNIQUE INDEX usuarios_usuario_lower_unique ON usuarios (LOWER(usuario))');

        $this->replaceForeignKey('usuarios', 'cliente_id', 'clientes', 'cascade');
        $this->replaceForeignKey('empresas', 'cliente_id', 'clientes', 'cascade');
        $this->replaceForeignKey('solicitudes_sistema', 'cliente_id', 'clientes', 'cascade');
        $this->replaceForeignKey('proyectos', 'cliente_id', 'clientes', 'cascade');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION viti_eliminar_cliente_de_usuario()
                RETURNS TRIGGER AS $$
                BEGIN
                    IF OLD.cliente_id IS NOT NULL AND pg_trigger_depth() = 1 THEN
                        DELETE FROM clientes WHERE id = OLD.cliente_id;
                    END IF;
                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER usuarios_eliminar_cliente
                AFTER DELETE ON usuarios
                FOR EACH ROW
                EXECUTE FUNCTION viti_eliminar_cliente_de_usuario();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS usuarios_eliminar_cliente ON usuarios; DROP FUNCTION IF EXISTS viti_eliminar_cliente_de_usuario();');
        }

        $this->replaceForeignKey('proyectos', 'cliente_id', 'clientes', 'restrict');
        $this->replaceForeignKey('solicitudes_sistema', 'cliente_id', 'clientes', 'restrict');
        $this->replaceForeignKey('empresas', 'cliente_id', 'clientes', 'null');
        $this->replaceForeignKey('usuarios', 'cliente_id', 'clientes', 'null');

        DB::statement('DROP INDEX IF EXISTS usuarios_usuario_lower_unique');

        if (Schema::hasColumn('usuarios', 'correo')) {
            Schema::table('usuarios', function (Blueprint $table): void {
                $table->dropColumn('correo');
            });
        }
    }

    private function replaceForeignKey(string $tableName, string $column, string $parent, string $onDelete): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($column, $parent, $onDelete): void {
            $table->dropForeign([$column]);
            $foreign = $table->foreign($column)->references('id')->on($parent);
            match ($onDelete) {
                'cascade' => $foreign->cascadeOnDelete(),
                'null' => $foreign->nullOnDelete(),
                default => $foreign->restrictOnDelete(),
            };
        });
    }
};
