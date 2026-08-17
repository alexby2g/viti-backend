<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Services\TenantContext;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ElectrofrioLegacyWorkflowController extends Controller
{
    public function guardar(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $response = app(ElectrofrioController::class)->guardarOrden($request, $tenants);
        $id = (int) data_get($response->getData(true), 'data.id');
        if ($id > 0) {
            $this->setState($request, $empresa->id, $id, null, 'cita_programada', 'creacion', 'Servicio registrado desde una interfaz compatible.');
        }
        return $response;
    }

    public function actualizar(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $before = $this->order($empresa->id, $id);
        $previous = $this->currentState($before);
        $response = app(ElectrofrioController::class)->actualizarOrden($request, $tenants, $id);
        $after = $this->order($empresa->id, $id);
        $target = $this->stateFromLegacyOrder($after);
        $this->setState($request, $empresa->id, $id, $previous, $target, 'compatibilidad', 'El estado se sincronizó desde la edición compatible de la orden.');
        return $response;
    }

    public function decision(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $before = $this->order($empresa->id, $id);
        $previous = $this->currentState($before);
        $decision = (string) $request->input('decision');
        $response = app(ElectrofrioController::class)->decision($request, $tenants, $id);

        if ($decision === 'aceptado') {
            $this->setState($request, $empresa->id, $id, $previous, 'aprobado', 'compatibilidad', 'El cliente aprobó la propuesta desde una interfaz compatible.');
            $this->setState($request, $empresa->id, $id, 'aprobado', 'servicio_en_proceso', 'compatibilidad', 'El servicio quedó habilitado para ejecución.');
        } elseif ($decision === 'rechazado') {
            $reason = trim((string) $request->input('motivo_rechazo'));
            $this->setState($request, $empresa->id, $id, $previous, 'no_aprobado', 'compatibilidad', $reason ?: 'El cliente no aprobó la propuesta.');
        }

        return $response;
    }

    public function finalizar(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $before = $this->order($empresa->id, $id);
        $previous = $this->currentState($before);
        $response = app(ElectrofrioController::class)->finalizar($request, $tenants, $id);
        $after = $this->order($empresa->id, $id);

        $this->setState($request, $empresa->id, $id, $previous, 'servicio_terminado', 'compatibilidad', 'El trabajo técnico fue marcado como terminado desde una interfaz compatible.');
        DB::table('electrofrio_ordenes')->where('empresa_id', $empresa->id)->where('id', $id)->update([
            'servicio_terminado_at' => $after->servicio_terminado_at ?: now(),
            'updated_at' => now(),
        ]);

        $target = $this->shouldWaitForPayment($request, $tenants, $empresa, $after) ? 'pendiente_pago' : 'finalizado';
        if ($target === 'pendiente_pago') {
            DB::table('electrofrio_ordenes')->where('empresa_id', $empresa->id)->where('id', $id)->update([
                'etapa' => 'servicio',
                'finalizada_at' => null,
                'updated_at' => now(),
            ]);
            $this->setState($request, $empresa->id, $id, 'servicio_terminado', 'pendiente_pago', 'compatibilidad', 'Trabajo terminado. Queda saldo pendiente antes del cierre definitivo.');
        } else {
            $this->setState($request, $empresa->id, $id, 'servicio_terminado', 'finalizado', 'compatibilidad', 'Servicio finalizado sin saldo pendiente.');
        }

        Audit::log($request, 'aires_flujo_legacy_finalizado', null, 'Se sincronizó la finalización de un servicio desde una interfaz compatible.', [
            'orden_id' => $id,
            'estado_final' => $target,
        ]);

        return response()->json(['data' => $this->order($empresa->id, $id)]);
    }

    public function pago(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $response = app(ElectrofrioController::class)->registrarPago($request, $tenants, $id);
        $order = $this->order($empresa->id, $id);
        $current = $this->currentState($order);

        if (in_array($current, ['servicio_terminado', 'pendiente_pago'], true) && $this->saldo($empresa->id, $order) <= 0.001) {
            DB::table('electrofrio_ordenes')->where('empresa_id', $empresa->id)->where('id', $id)->update([
                'estado_actual' => 'finalizado',
                'estado_actualizado_at' => now(),
                'etapa' => 'cerrada',
                'finalizada_at' => now(),
                'updated_at' => now(),
            ]);
            $this->record($request, $empresa->id, $id, $current, 'finalizado', 'compatibilidad', 'El saldo se completó mediante el registro de pago compatible.');
        }

        return $response;
    }

    private function shouldWaitForPayment(Request $request, TenantContext $tenants, Empresa $empresa, object $order): bool
    {
        if (!$tenants->canUse($request->user(), $empresa, 'pagos')) return false;
        if ((float) $order->total <= 0) return false;
        return $this->saldo($empresa->id, $order) > 0.001;
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

    private function stateFromLegacyOrder(object $order): string
    {
        return match (true) {
            $order->etapa === 'cerrada' && $order->decision_cliente === 'rechazado' => 'no_aprobado',
            $order->etapa === 'cerrada' => 'finalizado',
            $order->decision_cliente === 'aceptado' || $order->etapa === 'servicio' => 'servicio_en_proceso',
            trim((string) $order->propuesta) !== '' || $order->etapa === 'propuesta' => 'esperando_aprobacion',
            trim((string) $order->diagnostico) !== '' || $order->etapa === 'diagnostico' => 'diagnostico_realizado',
            default => 'cita_programada',
        };
    }

    private function currentState(object $order): string
    {
        $state = trim((string) ($order->estado_actual ?? ''));
        return $state !== '' ? $state : $this->stateFromLegacyOrder($order);
    }

    private function setState(Request $request, int $empresaId, int $orderId, ?string $previous, string $state, string $type, string $note): void
    {
        $order = $this->order($empresaId, $orderId);
        $current = $this->currentState($order);
        if ($previous === null) $previous = $current === $state ? null : $current;

        DB::table('electrofrio_ordenes')->where('empresa_id', $empresaId)->where('id', $orderId)->update([
            'estado_actual' => $state,
            'estado_actualizado_at' => now(),
            'updated_at' => now(),
        ]);

        $already = DB::table('electrofrio_orden_estados')
            ->where('empresa_id', $empresaId)
            ->where('orden_id', $orderId)
            ->where('estado', $state)
            ->when($previous === null, fn ($query) => $query->whereNull('estado_anterior'), fn ($query) => $query->where('estado_anterior', $previous))
            ->where('tipo_cambio', $type)
            ->exists();
        if (!$already && $previous !== $state) {
            $this->record($request, $empresaId, $orderId, $previous, $state, $type, $note);
        }
    }

    private function record(Request $request, int $empresaId, int $orderId, ?string $previous, string $state, string $type, string $note): void
    {
        DB::table('electrofrio_orden_estados')->insert([
            'empresa_id' => $empresaId,
            'orden_id' => $orderId,
            'estado_anterior' => $previous,
            'estado' => $state,
            'tipo_cambio' => $type,
            'observacion' => $note,
            'cambiado_por' => $request->user()?->id,
            'cambiado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(int $empresaId, int $id): object
    {
        $order = DB::table('electrofrio_ordenes')->where('empresa_id', $empresaId)->where('id', $id)->first();
        abort_unless($order, 404, 'El servicio solicitado no existe en este negocio.');
        return $order;
    }
}
