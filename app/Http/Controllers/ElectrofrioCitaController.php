<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Empresa};
use App\Services\{SubscriptionAccessService,TenantContext};
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ElectrofrioCitaController extends Controller
{
    private const REPROGRAMMABLE_STATES = ['cita_programada', 'en_visita'];

    public function reprogramar(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        $data = $request->validate([
            'fecha_cita' => ['required', 'date', 'after_or_equal:today'],
            'hora_cita' => ['nullable', 'date_format:H:i'],
            'direccion_servicio' => ['nullable', 'string', 'max:1000'],
            'referencia_ubicacion' => ['nullable', 'string', 'max:1000'],
            'motivo' => ['required', 'string', 'min:3', 'max:3000'],
        ]);

        $result = DB::transaction(function () use ($request, $empresa, $id, $data): object {
            $order = DB::table('electrofrio_ordenes')
                ->where('empresa_id', $empresa->id)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            abort_unless($order, 404, 'La cita solicitada no existe en este negocio.');

            $current = $this->currentState($order);
            abort_unless(
                in_array($current, self::REPROGRAMMABLE_STATES, true),
                422,
                'La cita ya avanzó a una etapa que no permite reprogramación. Abre el servicio para gestionar el siguiente paso.'
            );

            $oldDate = (string) ($order->fecha_cita ?? '');
            $oldTime = $this->time((string) ($order->hora_cita ?? ''));
            $newDate = (string) $data['fecha_cita'];
            $newTime = $this->time((string) ($data['hora_cita'] ?? ''));
            $reason = trim((string) $data['motivo']);

            $updates = [
                'fecha_cita' => $newDate,
                'hora_cita' => $newTime !== '' ? $newTime : null,
                'estado_actual' => 'cita_programada',
                'estado_actualizado_at' => now(),
                'etapa' => 'cita',
                'finalizada_at' => null,
                'updated_at' => now(),
            ];
            if (array_key_exists('direccion_servicio', $data)) {
                $updates['direccion_servicio'] = trim((string) ($data['direccion_servicio'] ?? '')) ?: $order->direccion_servicio;
            }
            if (array_key_exists('referencia_ubicacion', $data)) {
                $updates['referencia_ubicacion'] = trim((string) ($data['referencia_ubicacion'] ?? '')) ?: null;
            }

            DB::table('electrofrio_ordenes')
                ->where('empresa_id', $empresa->id)
                ->where('id', $id)
                ->update($updates);

            $oldSlot = trim($oldDate.' '.($oldTime !== '' ? $oldTime : 'sin hora'));
            $newSlot = trim($newDate.' '.($newTime !== '' ? $newTime : 'sin hora'));
            $observation = "Reprogramación: {$oldSlot} → {$newSlot}. Motivo: {$reason}";

            DB::table('electrofrio_orden_estados')->insert([
                'empresa_id' => $empresa->id,
                'orden_id' => $id,
                'estado_anterior' => $current,
                'estado' => 'cita_programada',
                'tipo_cambio' => 'reprogramacion',
                'observacion' => $observation,
                'cambiado_por' => $request->user()->id,
                'cambiado_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('electrofrio_ordenes')
                ->where('empresa_id', $empresa->id)
                ->where('id', $id)
                ->first();
        });

        Audit::log($request, 'aires_cita_reprogramada', null, 'Se reprogramó una cita del sistema de servicios de aire acondicionado.', [
            'orden_id' => $id,
            'fecha_cita' => $result->fecha_cita,
            'hora_cita' => $result->hora_cita,
            'motivo' => trim((string) $data['motivo']),
        ]);

        return response()->json([
            'message' => 'Cita reprogramada y registrada en el historial.',
            'data' => $result,
        ]);
    }

    private function currentState(object $order): string
    {
        $value = trim((string) ($order->estado_actual ?? ''));
        if ($value !== '') return $value;
        return match (true) {
            $order->etapa === 'cerrada' && $order->decision_cliente === 'rechazado' => 'no_aprobado',
            $order->etapa === 'cerrada' => 'finalizado',
            $order->etapa === 'servicio' => 'servicio_en_proceso',
            $order->etapa === 'propuesta' => 'esperando_aprobacion',
            $order->etapa === 'diagnostico' => 'diagnostico_realizado',
            default => 'cita_programada',
        };
    }

    private function time(string $value): string
    {
        return $value !== '' ? substr($value, 0, 5) : '';
    }

    private function empresa(Request $request, TenantContext $tenants): Empresa
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanUse($request->user(), $empresa, 'ordenes');

        if (!$request->user()->isPlatformAdmin()) {
            $app = Aplicacion::query()
                ->where('empresa_id', $empresa->id)
                ->whereHas('catalogo', fn ($query) => $query->where('clave', 'electrofrio'))
                ->with('suscripcion')
                ->latest('id')
                ->first();
            abort_unless($app, 404, 'Este negocio no tiene asignado el Sistema de Gestión de Servicios de Aire Acondicionado.');
            abort_unless((bool) $app->acceso_cliente, 403, 'El sistema todavía no fue entregado a este negocio.');
            abort_unless($app->estado === 'activo', 403, 'El acceso al sistema está suspendido.');
            app(SubscriptionAccessService::class)->assertCanUse($app);
        }

        return $empresa;
    }
}
