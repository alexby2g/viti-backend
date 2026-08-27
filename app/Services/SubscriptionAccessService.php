<?php

namespace App\Services;

use App\Models\Aplicacion;
use App\Models\Suscripcion;
use App\Models\SuscripcionPago;

class SubscriptionAccessService
{
    private const DUE_SOON_DAYS = 5;

    public function refresh(?Suscripcion $subscription): ?Suscripcion
    {
        if (!$subscription) return null;
        if ($subscription->estado === 'cancelada') return $subscription;

        $today = now()->startOfDay();
        $trialEnd = $subscription->prueba_hasta?->copy()->startOfDay();
        $firstChargeComplete = $this->firstChargeComplete($subscription);

        if ($subscription->primer_cobro_monto !== null && !$firstChargeComplete) {
            if ($subscription->estado !== 'suspendida') {
                $subscription->update(['estado' => 'suspendida']);
                $subscription->refresh();
            }
            return $subscription;
        }

        if ($trialEnd && $today->lte($trialEnd)) {
            if ($subscription->estado !== 'activa') {
                $subscription->update(['estado'=>'activa']);
                $subscription->refresh();
            }
            return $subscription;
        }

        $due = $subscription->fecha_vencimiento?->copy()->startOfDay();
        if (!$due) return $subscription;

        if ($today->lte($due)) {
            $status = 'activa';
        } elseif ($today->lte($due->copy()->addDays((int)$subscription->dias_gracia))) {
            $status = 'gracia';
        } else {
            $status = 'suspendida';
        }

        if ($subscription->estado !== $status) {
            $subscription->update(['estado'=>$status]);
            $subscription->refresh();
        }

        return $subscription;
    }

    public function statusFor(Aplicacion $app): ?array
    {
        $subscription = $this->refresh($app->suscripcion);
        if (!$subscription) return null;

        $today = now()->startOfDay();
        $trialEnd = $subscription->prueba_hasta?->copy()->startOfDay();
        $due = $subscription->fecha_vencimiento?->copy()->startOfDay();
        $graceEnd = $due?->copy()->addDays((int)$subscription->dias_gracia);

        $firstChargeAmount = $subscription->primer_cobro_monto !== null
            ? (float)$subscription->primer_cobro_monto
            : null;
        $firstChargeConfirmed = $this->firstChargeConfirmedAmount($subscription, $firstChargeAmount);
        $firstChargeRemaining = $firstChargeAmount !== null
            ? max(0.0, round($firstChargeAmount - $firstChargeConfirmed, 2))
            : null;
        $firstChargeComplete = $firstChargeAmount !== null
            ? $firstChargeRemaining <= 0.0
            : false;
        $firstChargePaid = (bool)$subscription->primer_cobro_pagado || $firstChargeComplete;

        $inTrial = $trialEnd
            && $today->lte($trialEnd)
            && ($firstChargeAmount === null || $firstChargeComplete);

        $daysToDue = $due && $today->lte($due) ? (int)$today->diffInDays($due) : null;
        $daysLate = $due && $today->gt($due) ? (int)$due->diffInDays($today) : 0;
        $stage = $this->stage($subscription,$inTrial,$daysToDue);
        $subscriptionMayUse = $inTrial || in_array($subscription->estado,['activa','gracia'],true);
        $manualBlock = (bool)$app->acceso_bloqueado_manual;
        $canUse = $subscriptionMayUse && !$manualBlock && $app->estado !== 'retirado';

        return [
            'id'=>$subscription->id,
            'plan'=>$subscription->plan,
            'monto'=>(float)$subscription->monto,
            'frecuencia'=>$subscription->frecuencia,
            'moneda'=>$subscription->moneda,
            'fecha_inicio'=>$subscription->fecha_inicio?->format('Y-m-d'),
            'prueba_hasta'=>$subscription->prueba_hasta?->format('Y-m-d'),
            'en_prueba'=>(bool)$inTrial,
            'primer_cobro_monto'=>$firstChargeAmount,
            'primer_cobro_desde'=>$subscription->primer_cobro_desde?->format('Y-m-d'),
            'primer_cobro_hasta'=>$subscription->primer_cobro_hasta?->format('Y-m-d'),
            'primer_cobro_confirmado'=>$firstChargeConfirmed,
            'primer_cobro_restante'=>$firstChargeRemaining,
            'primer_cobro_completo'=>$firstChargeComplete,
            'primer_cobro_pagado'=>$firstChargePaid,
            'fecha_vencimiento'=>$subscription->fecha_vencimiento?->format('Y-m-d'),
            'dias_gracia'=>(int)$subscription->dias_gracia,
            'gracia_hasta'=>$graceEnd?->format('Y-m-d'),
            'dias_para_vencer'=>$daysToDue,
            'dias_mora'=>$daysLate,
            'estado'=>$subscription->estado,
            'etapa_cobro'=>$stage,
            'bloqueado_manual'=>$manualBlock,
            'motivo_bloqueo_manual'=>$app->bloqueo_manual_motivo,
            'bloqueado_manual_at'=>$app->bloqueado_manualmente_at?->toISOString(),
            'requiere_pago'=>!$inTrial && $subscription->estado !== 'cancelada',
            'puede_usar'=>$canUse,
            'mensaje_cobro'=>$this->message($stage,$daysToDue,$daysLate,$graceEnd?->format('Y-m-d')),
        ];
    }

    public function assertCanUse(Aplicacion $app): void
    {
        if ($app->acceso_bloqueado_manual) {
            abort(403, 'El acceso a esta aplicación fue bloqueado manualmente por el administrador.');
        }

        if ($app->estado === 'retirado') {
            abort(410, 'Esta aplicación ya no está disponible.');
        }

        $status = $this->statusFor($app);
        if (!$status) return;

        abort_unless($status['puede_usar'], 402, 'Tu suscripción VITI está suspendida. Regulariza el pago para volver a utilizar la aplicación.');
    }

    public function blockManually(Aplicacion $app, int $userId, string $reason): Aplicacion
    {
        $app->update([
            'acceso_bloqueado_manual' => true,
            'bloqueo_manual_motivo' => trim($reason),
            'bloqueado_manualmente_at' => now(),
            'bloqueado_manualmente_por' => $userId,
        ]);
        return $app->refresh();
    }

    public function unblockManually(Aplicacion $app): Aplicacion
    {
        $app->update([
            'acceso_bloqueado_manual' => false,
            'bloqueo_manual_motivo' => null,
            'bloqueado_manualmente_at' => null,
            'bloqueado_manualmente_por' => null,
        ]);
        return $app->refresh();
    }

    private function firstChargeConfirmedAmount(Suscripcion $subscription, ?float $amount): float
    {
        if ($amount === null) return 0.0;
        if ((bool)$subscription->primer_cobro_pagado) return $amount;

        $query = SuscripcionPago::query()
            ->where('suscripcion_id', $subscription->id)
            ->where('estado_revision', 'confirmado');

        if ($subscription->primer_cobro_desde) {
            $query->whereDate('fecha_pago', '>=', $subscription->primer_cobro_desde->format('Y-m-d'));
        }
        if ($subscription->primer_cobro_hasta) {
            $query->whereDate('fecha_pago', '<=', $subscription->primer_cobro_hasta->format('Y-m-d'));
        }

        return min($amount, round((float)$query->sum('monto'), 2));
    }

    private function firstChargeComplete(Suscripcion $subscription): bool
    {
        $amount = $subscription->primer_cobro_monto !== null
            ? (float)$subscription->primer_cobro_monto
            : null;

        if ($amount === null) return (bool)$subscription->primer_cobro_pagado;
        if ((bool)$subscription->primer_cobro_pagado) return true;

        return $this->firstChargeConfirmedAmount($subscription, $amount) >= $amount;
    }

    private function stage(Suscripcion $subscription, bool $inTrial, ?int $daysToDue): string
    {
        if ($subscription->estado === 'cancelada') return 'cancelada';
        if ($inTrial) return 'prueba';
        if ($subscription->estado === 'suspendida') return 'suspendida';
        if ($subscription->estado === 'gracia') return 'gracia';
        if ($daysToDue !== null && $daysToDue <= self::DUE_SOON_DAYS) return 'por_vencer';
        return 'al_dia';
    }

    private function message(string $stage, ?int $daysToDue, int $daysLate, ?string $graceEnd): string
    {
        return match ($stage) {
            'prueba' => 'La suscripción está dentro del periodo de prueba gratuito.',
            'por_vencer' => $daysToDue === 0 ? 'La suscripción vence hoy.' : "La suscripción vence en {$daysToDue} día(s).",
            'gracia' => "El pago está vencido hace {$daysLate} día(s). El acceso continúa en periodo de gracia hasta {$graceEnd}.",
            'suspendida' => 'La suscripción superó el periodo de gracia y el acceso está suspendido.',
            'cancelada' => 'La suscripción está cancelada.',
            default => 'La suscripción está al día.',
        };
    }
}
