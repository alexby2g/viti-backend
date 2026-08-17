<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Empresa};
use App\Services\{SubscriptionAccessService,TenantContext};
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ElectrofrioGarantiaOperativaController extends Controller
{
    public function reingreso(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        $data = $request->validate([
            'fecha_cita' => ['required', 'date', 'after_or_equal:today'],
            'hora_cita' => ['nullable', 'date_format:H:i'],
            'direccion_servicio' => ['nullable', 'string', 'max:255'],
            'referencia_ubicacion' => ['nullable', 'string', 'max:255'],
            'prioridad' => ['nullable', Rule::in(['baja', 'normal', 'alta', 'urgente'])],
            'motivo' => ['required', 'string', 'min:3', 'max:5000'],
        ]);

        $newId = DB::transaction(function () use ($request, $empresa, $id, $data): int {
            $original = DB::table('electrofrio_ordenes')
                ->where('empresa_id', $empresa->id)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            abort_unless($original, 404, 'El servicio original no existe en este negocio.');
            abort_unless($original->garantia_fin, 422, 'El servicio seleccionado no tiene una garantía registrada.');

            $today = now()->toDateString();
            abort_if((string) $original->garantia_fin < $today, 422, 'La garantía del servicio ya venció.');
            if ($original->garantia_inicio) {
                abort_if((string) $original->garantia_inicio > $today, 422, 'La garantía todavía no se encuentra vigente.');
            }

            $current = $this->currentState($original);
            abort_unless(
                in_array($current, ['servicio_terminado', 'pendiente_pago', 'finalizado'], true) || (bool) $original->servicio_terminado_at,
                422,
                'La garantía solo puede generar un reingreso después de terminar el trabajo técnico original.'
            );

            $reason = trim((string) $data['motivo']);
            $address = trim((string) ($data['direccion_servicio'] ?? '')) ?: (string) $original->direccion_servicio;
            $reference = array_key_exists('referencia_ubicacion', $data)
                ? (trim((string) ($data['referencia_ubicacion'] ?? '')) ?: null)
                : $original->referencia_ubicacion;

            $newId = DB::table('electrofrio_ordenes')->insertGetId([
                'empresa_id' => $empresa->id,
                'codigo' => 'TEMP-'.bin2hex(random_bytes(6)),
                'cliente_id' => $original->cliente_id,
                'equipo_id' => $original->equipo_id,
                'tecnico_id' => null,
                'fecha_cita' => $data['fecha_cita'],
                'hora_cita' => $data['hora_cita'] ?? null,
                'direccion_servicio' => $address,
                'referencia_ubicacion' => $reference,
                'problema_reportado' => 'Reingreso por garantía: '.$reason,
                'prioridad' => $data['prioridad'] ?? 'alta',
                'etapa' => 'cita',
                'estado_actual' => 'cita_programada',
                'estado_actualizado_at' => now(),
                'decision_cliente' => 'pendiente',
                'tipo_servicio' => 'Reingreso por garantía',
                'orden_origen_garantia_id' => $original->id,
                'motivo_reingreso_garantia' => $reason,
                'costo_mano_obra' => 0,
                'costo_materiales' => 0,
                'descuento' => 0,
                'total' => 0,
                'garantia_dias' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $code = 'EF-'.now()->format('Ym').'-'.str_pad((string) $newId, 5, '0', STR_PAD_LEFT);
            DB::table('electrofrio_ordenes')->where('id', $newId)->update(['codigo' => $code]);

            DB::table('electrofrio_orden_estados')->insert([
                'empresa_id' => $empresa->id,
                'orden_id' => $newId,
                'estado_anterior' => null,
                'estado' => 'cita_programada',
                'tipo_cambio' => 'garantia',
                'observacion' => "Reingreso vinculado al servicio {$original->codigo}. Motivo: {$reason}",
                'cambiado_por' => $request->user()->id,
                'cambiado_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $newId;
        });

        Audit::log($request, 'aires_reingreso_garantia_creado', null, 'Se registró un reingreso vinculado a una garantía vigente.', [
            'orden_origen_id' => $id,
            'orden_reingreso_id' => $newId,
            'motivo' => trim((string) $data['motivo']),
        ]);

        return response()->json([
            'message' => 'Reingreso por garantía registrado. La cobertura se evaluará durante el diagnóstico.',
            'data' => $this->find($empresa->id, $newId),
        ], 201);
    }

    private function find(int $empresaId, int $id): object
    {
        $order = DB::table('electrofrio_ordenes as o')
            ->leftJoin('electrofrio_ordenes as original', function ($join): void {
                $join->on('original.id', '=', 'o.orden_origen_garantia_id')
                    ->on('original.empresa_id', '=', 'o.empresa_id');
            })
            ->where('o.empresa_id', $empresaId)
            ->where('o.id', $id)
            ->select('o.*', 'original.codigo as garantia_origen_codigo')
            ->first();
        abort_unless($order, 404, 'El reingreso solicitado no existe en este negocio.');
        return $order;
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

    private function empresa(Request $request, TenantContext $tenants): Empresa
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanUse($request->user(), $empresa, 'ordenes');
        $tenants->assertCanUse($request->user(), $empresa, 'garantias');

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
