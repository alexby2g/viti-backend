<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Empresa};
use App\Services\{SubscriptionAccessService,TenantContext};
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ElectrofrioFlujoEstadoController extends Controller
{
    private const STATES = [
        'cita_programada' => ['label' => 'Cita programada', 'color' => 'blue', 'icon' => 'event'],
        'en_visita' => ['label' => 'En visita / revisión', 'color' => 'indigo', 'icon' => 'location_on'],
        'diagnostico_realizado' => ['label' => 'Diagnóstico realizado', 'color' => 'purple', 'icon' => 'troubleshoot'],
        'propuesta_enviada' => ['label' => 'Propuesta enviada', 'color' => 'deep-orange', 'icon' => 'request_quote'],
        'esperando_aprobacion' => ['label' => 'Esperando aprobación', 'color' => 'orange', 'icon' => 'hourglass_top'],
        'aprobado' => ['label' => 'Aprobado', 'color' => 'positive', 'icon' => 'thumb_up'],
        'no_aprobado' => ['label' => 'No aprobado', 'color' => 'negative', 'icon' => 'thumb_down'],
        'servicio_en_proceso' => ['label' => 'Servicio en proceso', 'color' => 'teal', 'icon' => 'build'],
        'servicio_terminado' => ['label' => 'Servicio terminado', 'color' => 'cyan-8', 'icon' => 'engineering'],
        'pendiente_pago' => ['label' => 'Pendiente de pago', 'color' => 'amber-9', 'icon' => 'payments'],
        'finalizado' => ['label' => 'Finalizado', 'color' => 'positive', 'icon' => 'task_alt'],
        'cancelado' => ['label' => 'Cancelado', 'color' => 'grey-7', 'icon' => 'cancel'],
    ];

    private const TRANSITIONS = [
        'cita_programada' => ['en_visita', 'cancelado'],
        'en_visita' => ['diagnostico_realizado', 'cancelado'],
        'diagnostico_realizado' => ['propuesta_enviada', 'cancelado'],
        'propuesta_enviada' => ['esperando_aprobacion'],
        'esperando_aprobacion' => ['aprobado', 'no_aprobado'],
        'aprobado' => ['servicio_en_proceso'],
        'servicio_en_proceso' => ['servicio_terminado'],
        'servicio_terminado' => ['pendiente_pago', 'finalizado'],
        'pendiente_pago' => ['finalizado'],
        'no_aprobado' => [],
        'finalizado' => [],
        'cancelado' => [],
    ];

    public function catalogo(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        $canManage = $request->user()->isPlatformAdmin() || $tenants->canManage($request->user(), $empresa);

        return response()->json([
            'data' => collect(self::STATES)->map(fn ($meta, $key) => ['value' => $key, ...$meta])->values(),
            'meta' => ['puede_corregir' => $canManage],
        ]);
    }

    public function historial(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        $order = $this->orden($empresa->id, $id);

        return response()->json([
            'data' => $this->history($empresa->id, $id),
            'meta' => $this->flowMeta($request, $tenants, $empresa, $order),
        ]);
    }

    public function cambiar(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        $data = $request->validate([
            'estado' => ['required', Rule::in(array_keys(self::STATES))],
            'observacion' => ['nullable', 'string', 'max:3000'],
            'correccion' => ['sometimes', 'boolean'],
        ]);
        $target = $data['estado'];
        $isCorrection = (bool) ($data['correccion'] ?? false);
        $note = trim((string) ($data['observacion'] ?? '')) ?: null;

        if ($isCorrection) {
            $tenants->assertCanManage($request->user(), $empresa);
            abort_if(in_array($target, ['no_aprobado', 'cancelado'], true) && !$note, 422, 'Indica el motivo de la corrección.');
        }

        DB::transaction(function () use ($request, $tenants, $empresa, $id, $target, $isCorrection, $note): void {
            $order = DB::table('electrofrio_ordenes')
                ->where('empresa_id', $empresa->id)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            abort_unless($order, 404, 'El servicio solicitado no existe en este negocio.');

            $current = $this->currentState($order);
            abort_if($current === $target, 422, 'El servicio ya se encuentra en ese estado.');

            if (!$isCorrection) {
                $allowed = self::TRANSITIONS[$current] ?? [];
                abort_unless(in_array($target, $allowed, true), 422, 'Ese cambio de estado no corresponde al flujo actual.');
            }

            $this->assertPrerequisites($request, $tenants, $empresa, $order, $target, $note);
            $updates = $this->orderUpdates($order, $target, $note);
            DB::table('electrofrio_ordenes')->where('id', $id)->update($updates + ['updated_at' => now()]);

            DB::table('electrofrio_orden_estados')->insert([
                'empresa_id' => $empresa->id,
                'orden_id' => $id,
                'estado_anterior' => $current,
                'estado' => $target,
                'tipo_cambio' => $isCorrection ? 'correccion' : 'avance',
                'observacion' => $note,
                'cambiado_por' => $request->user()->id,
                'cambiado_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Audit::log($request, $isCorrection ? 'aires_estado_corregido' : 'aires_estado_actualizado', null, 'Se actualizó el estado operativo de una orden de servicio.', [
            'orden_id' => $id,
            'estado' => $target,
            'observacion' => $note,
        ]);

        $order = $this->orden($empresa->id, $id);
        return response()->json([
            'message' => 'Estado actualizado.',
            'data' => $order,
            'historial' => $this->history($empresa->id, $id),
            'meta' => $this->flowMeta($request, $tenants, $empresa, $order),
        ]);
    }

    private function assertPrerequisites(Request $request, TenantContext $tenants, Empresa $empresa, object $order, string $target, ?string $note): void
    {
        if ($target === 'diagnostico_realizado') {
            abort_if(trim((string) $order->diagnostico) === '', 422, 'Registra el diagnóstico antes de marcarlo como realizado.');
        }
        if (in_array($target, ['propuesta_enviada', 'esperando_aprobacion', 'aprobado', 'no_aprobado'], true)) {
            abort_if(trim((string) $order->diagnostico) === '', 422, 'Registra el diagnóstico antes de continuar.');
            abort_if(trim((string) $order->propuesta) === '', 422, 'Registra la propuesta antes de continuar.');
        }
        if (in_array($target, ['no_aprobado', 'cancelado'], true)) {
            abort_if(!$note, 422, 'Indica el motivo antes de cerrar el servicio.');
        }
        if (in_array($target, ['servicio_en_proceso', 'servicio_terminado', 'pendiente_pago', 'finalizado'], true)) {
            abort_unless($order->decision_cliente === 'aceptado' || $this->currentState($order) === 'aprobado', 422, 'El cliente debe aprobar la propuesta antes de ejecutar el servicio.');
        }
        if (in_array($target, ['servicio_terminado', 'pendiente_pago', 'finalizado'], true)) {
            abort_if(trim((string) $order->trabajo_realizado) === '', 422, 'Describe el trabajo realizado antes de marcar el servicio como terminado.');
        }
        if ($target === 'pendiente_pago') {
            abort_unless($tenants->canUse($request->user(), $empresa, 'pagos'), 403, 'El módulo Pagos no está habilitado para este negocio.');
            abort_if($this->saldo($empresa->id, $order) <= 0.001, 422, 'No existe saldo pendiente. Puedes finalizar directamente el servicio.');
        }
        if ($target === 'finalizado' && $tenants->canUse($request->user(), $empresa, 'pagos') && (float) $order->total > 0) {
            abort_if($this->saldo($empresa->id, $order) > 0.001, 422, 'Existe un saldo pendiente. Registra el pago antes de finalizar.');
        }
    }

    private function orderUpdates(object $order, string $target, ?string $note): array
    {
        $updates = [
            'estado_actual' => $target,
            'estado_actualizado_at' => now(),
            'etapa' => $this->legacyStage($target),
        ];

        if ($target === 'aprobado') {
            $updates['decision_cliente'] = 'aceptado';
            $updates['decision_at'] = now();
            $updates['motivo_rechazo'] = null;
        }
        if ($target === 'no_aprobado') {
            $updates['decision_cliente'] = 'rechazado';
            $updates['decision_at'] = now();
            $updates['motivo_rechazo'] = $note;
            $updates['finalizada_at'] = now();
        }
        if ($target === 'cancelado') {
            $updates['finalizada_at'] = now();
        }
        if ($target === 'servicio_terminado') {
            $updates['servicio_terminado_at'] = now();
        }
        if (in_array($target, ['pendiente_pago', 'finalizado'], true) && !$order->servicio_terminado_at) {
            $updates['servicio_terminado_at'] = now();
        }
        if ($target === 'finalizado') {
            $updates['finalizada_at'] = now();
        }
        if (!in_array($target, ['no_aprobado', 'cancelado', 'finalizado'], true)) {
            $updates['finalizada_at'] = null;
        }

        return $updates;
    }

    private function legacyStage(string $state): string
    {
        return match ($state) {
            'cita_programada', 'en_visita' => 'cita',
            'diagnostico_realizado' => 'diagnostico',
            'propuesta_enviada', 'esperando_aprobacion' => 'propuesta',
            'aprobado', 'servicio_en_proceso', 'servicio_terminado', 'pendiente_pago' => 'servicio',
            default => 'cerrada',
        };
    }

    private function currentState(object $order): string
    {
        $value = (string) ($order->estado_actual ?? '');
        if (isset(self::STATES[$value])) return $value;

        return match (true) {
            $order->etapa === 'cerrada' && $order->decision_cliente === 'rechazado' => 'no_aprobado',
            $order->etapa === 'cerrada' => 'finalizado',
            $order->etapa === 'servicio' => 'servicio_en_proceso',
            $order->etapa === 'propuesta' => 'esperando_aprobacion',
            $order->etapa === 'diagnostico' => 'diagnostico_realizado',
            default => 'cita_programada',
        };
    }

    private function saldo(int $empresaId, object $order): float
    {
        $paid = (float) DB::table('electrofrio_pagos')
            ->where('empresa_id', $empresaId)
            ->where('orden_id', $order->id)
            ->where('estado', 'pagado')
            ->sum('monto');
        return max(0, (float) $order->total - $paid);
    }

    private function history(int $empresaId, int $orderId)
    {
        return DB::table('electrofrio_orden_estados as h')
            ->leftJoin('usuarios as u', 'u.id', '=', 'h.cambiado_por')
            ->where('h.empresa_id', $empresaId)
            ->where('h.orden_id', $orderId)
            ->select('h.*', 'u.nombre as cambiado_por_nombre', 'u.apellido as cambiado_por_apellido')
            ->orderByDesc('h.cambiado_at')
            ->orderByDesc('h.id')
            ->get()
            ->map(function ($item) {
                $item->estado_label = self::STATES[$item->estado]['label'] ?? $item->estado;
                $item->estado_anterior_label = $item->estado_anterior ? (self::STATES[$item->estado_anterior]['label'] ?? $item->estado_anterior) : null;
                return $item;
            });
    }

    private function flowMeta(Request $request, TenantContext $tenants, Empresa $empresa, object $order): array
    {
        $current = $this->currentState($order);
        return [
            'estado_actual' => $current,
            'estado' => ['value' => $current, ...(self::STATES[$current] ?? [])],
            'siguientes' => collect(self::TRANSITIONS[$current] ?? [])->map(fn ($key) => ['value' => $key, ...self::STATES[$key]])->values(),
            'puede_corregir' => $request->user()->isPlatformAdmin() || $tenants->canManage($request->user(), $empresa),
            'saldo' => $this->saldo($empresa->id, $order),
        ];
    }

    private function orden(int $empresaId, int $id): object
    {
        $order = DB::table('electrofrio_ordenes')->where('empresa_id', $empresaId)->where('id', $id)->first();
        abort_unless($order, 404, 'El servicio solicitado no existe en este negocio.');
        return $order;
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
