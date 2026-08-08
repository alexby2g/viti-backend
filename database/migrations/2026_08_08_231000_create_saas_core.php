<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes_viti', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 60)->unique();
            $table->string('nombre', 100);
            $table->text('descripcion')->nullable();
            $table->unsignedInteger('max_usuarios')->nullable();
            $table->unsignedInteger('max_aplicaciones')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('catalogo_aplicaciones', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 80)->unique();
            $table->string('nombre', 140);
            $table->text('descripcion')->nullable();
            $table->string('icono', 80)->default('apps');
            $table->string('tipo', 30)->default('web');
            $table->string('ruta_base')->nullable();
            $table->boolean('activo')->default(true);
            $table->boolean('solicitable')->default(true);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::table('empresas', function (Blueprint $table): void {
            $table->foreignId('plan_viti_id')->nullable()->after('cliente_id')->constrained('planes_viti')->nullOnDelete();
            $table->string('moneda', 3)->default('BOB')->after('estado');
            $table->string('zona_horaria', 80)->default('America/La_Paz')->after('moneda');
            $table->json('configuracion')->nullable()->after('zona_horaria');
        });

        Schema::create('empresa_usuario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('rol_negocio', 30)->default('empleado'); // propietario | administrador | empleado
            $table->json('permisos')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['empresa_id','usuario_id']);
            $table->index(['usuario_id','activo']);
        });

        Schema::table('aplicaciones', function (Blueprint $table): void {
            $table->foreignId('catalogo_aplicacion_id')->nullable()->after('proyecto_id')->constrained('catalogo_aplicaciones')->nullOnDelete();
            $table->timestamp('provisionado_at')->nullable()->after('entregado_at');
        });

        Schema::create('alertas_saas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('aplicacion_id')->nullable()->constrained('aplicaciones')->cascadeOnDelete();
            $table->string('clave', 180);
            $table->string('tipo', 50)->default('info');
            $table->string('titulo', 180);
            $table->text('mensaje')->nullable();
            $table->string('ruta')->nullable();
            $table->timestamp('leida_at')->nullable();
            $table->timestamps();
            $table->unique(['usuario_id','clave']);
            $table->index(['usuario_id','leida_at']);
        });

        Schema::table('auditoria', function (Blueprint $table): void {
            $table->foreignId('empresa_id')->nullable()->after('usuario_id')->constrained('empresas')->nullOnDelete();
            $table->foreignId('aplicacion_id')->nullable()->after('empresa_id')->constrained('aplicaciones')->nullOnDelete();
        });

        $now = now();
        $planId = DB::table('planes_viti')->insertGetId([
            'codigo'=>'personalizado',
            'nombre'=>'Personalizado',
            'descripcion'=>'Plan base sin límites automáticos. Permite definir los límites comerciales más adelante.',
            'max_usuarios'=>null,
            'max_aplicaciones'=>null,
            'activo'=>true,
            'created_at'=>$now,
            'updated_at'=>$now,
        ]);

        DB::table('empresas')->whereNull('plan_viti_id')->update(['plan_viti_id'=>$planId]);

        DB::table('catalogo_aplicaciones')->insert([
            'clave'=>'peluqueria',
            'nombre'=>'Peluquería',
            'descripcion'=>'Agenda, clientes, servicios, personal, caja, productos y reportes para peluquerías y salones.',
            'icono'=>'content_cut',
            'tipo'=>'web',
            'ruta_base'=>'/mi-apps/peluqueria/inicio',
            'activo'=>true,
            'solicitable'=>true,
            'orden'=>10,
            'created_at'=>$now,
            'updated_at'=>$now,
        ]);

        $catalogId = DB::table('catalogo_aplicaciones')->where('clave','peluqueria')->value('id');
        DB::table('aplicaciones')->whereRaw('LOWER(nombre) LIKE ?', ['%peluquer%'])->update(['catalogo_aplicacion_id'=>$catalogId]);

        $users = DB::table('usuarios')->where('rol','cliente')->whereNotNull('cliente_id')->select('id','cliente_id')->get();
        foreach ($users as $user) {
            $businesses = DB::table('empresas')->where('cliente_id',$user->cliente_id)->pluck('id');
            foreach ($businesses as $empresaId) {
                DB::table('empresa_usuario')->insertOrIgnore([
                    'empresa_id'=>$empresaId,
                    'usuario_id'=>$user->id,
                    'rol_negocio'=>'propietario',
                    'activo'=>true,
                    'created_at'=>$now,
                    'updated_at'=>$now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('auditoria', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('aplicacion_id');
            $table->dropConstrainedForeignId('empresa_id');
        });
        Schema::dropIfExists('alertas_saas');
        Schema::table('aplicaciones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('catalogo_aplicacion_id');
            $table->dropColumn('provisionado_at');
        });
        Schema::dropIfExists('empresa_usuario');
        Schema::table('empresas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_viti_id');
            $table->dropColumn(['moneda','zona_horaria','configuracion']);
        });
        Schema::dropIfExists('catalogo_aplicaciones');
        Schema::dropIfExists('planes_viti');
    }
};
