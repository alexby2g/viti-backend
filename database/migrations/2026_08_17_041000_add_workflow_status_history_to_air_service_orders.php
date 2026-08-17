<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electrofrio_ordenes', function (Blueprint $table): void {
            $table->string('estado_actual', 40)->default('cita_programada')->after('etapa');
            $table->timestamp('estado_actualizado_at')->nullable()->after('estado_actual');
            $table->timestamp('servicio_terminado_at')->nullable()->after('finalizada_at');
            $table->index(['empresa_id', 'estado_actual']);
        });

        Schema::create('electrofrio_orden_estados', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('orden_id')->constrained('electrofrio_ordenes')->cascadeOnDelete();
            $table->string('estado_anterior', 40)->nullable();
            $table->string('estado', 40);
            $table->string('tipo_cambio', 20)->default('avance');
            $table->text('observacion')->nullable();
            $table->foreignId('cambiado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('cambiado_at');
            $table->timestamps();
            $table->index(['empresa_id', 'orden_id', 'cambiado_at']);
            $table->index(['empresa_id', 'estado']);
        });

        DB::table('electrofrio_ordenes')->orderBy('id')->chunkById(200, function ($orders): void {
            foreach ($orders as $order) {
                $estado = match (true) {
                    $order->etapa === 'cerrada' && $order->decision_cliente === 'rechazado' => 'no_aprobado',
                    $order->etapa === 'cerrada' => 'finalizado',
                    $order->etapa === 'servicio' => 'servicio_en_proceso',
                    $order->etapa === 'propuesta' => 'esperando_aprobacion',
                    $order->etapa === 'diagnostico' => 'diagnostico_realizado',
                    default => 'cita_programada',
                };
                $at = $order->updated_at ?: $order->created_at ?: now();
                DB::table('electrofrio_ordenes')->where('id', $order->id)->update([
                    'estado_actual' => $estado,
                    'estado_actualizado_at' => $at,
                ]);
                DB::table('electrofrio_orden_estados')->insert([
                    'empresa_id' => $order->empresa_id,
                    'orden_id' => $order->id,
                    'estado_anterior' => null,
                    'estado' => $estado,
                    'tipo_cambio' => 'migracion',
                    'observacion' => 'Estado inicial generado al habilitar el nuevo flujo de servicios.',
                    'cambiado_por' => null,
                    'cambiado_at' => $at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electrofrio_orden_estados');
        Schema::table('electrofrio_ordenes', function (Blueprint $table): void {
            $table->dropIndex(['empresa_id', 'estado_actual']);
            $table->dropColumn(['estado_actual', 'estado_actualizado_at', 'servicio_terminado_at']);
        });
    }
};
