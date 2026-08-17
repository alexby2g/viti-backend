<?php

namespace App\Services;

use App\Models\Aplicacion;
use App\Models\Suscripcion;

class SubscriptionAccessService
{
    private const DUE_SOON_DAYS = 5;

    public function refresh(?Suscripcion $subscription): ?Suscripcion
    {
        if (!$subscription) return null;
        if ($subscription->estado === 'cancelada') return $subscription;

        $today = now()->startOfDay();
        $trialEnd = $subscription->prueba_hasta?->copy()->startOfDay();
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
        $inTrial = $trialEnd && $today->lte($trialEnd);
        $daysToDue = $due && $today->lte($due) ? (int)$today->diffInDays($due) : null;
        $daysLate = $due && $today->gt($due) ? (int)$due->diffInDays($today) : 0;
        $stage = $this->stage($subscription,$inTrial,$daysToDue);
        $canUse = $stage === 'cancelada'
            ? false
            : ($inTrial || in_array($subscription->estado,['activa','gracia'],true));

        return [
            'id'=>$subscription->id,
            'plan'=>$subscription->plan,
            'monto'=>(float)$subscription->monto,
            'frecuencia'=>$subscription->frecuencia,
            'moneda'=>$subscription->moneda,
            'fecha_inicio'=>$subscription->fecha_inicio?->format('Y-m-d'),
            'prueba_hasta'=>$subscription->prueba_hasta?->format('Y-m-d'),
            'en_prueba'=>(bool)$inTrial,
            'primer_cobro_monto'=>$subscription->primer_cobro_monto !== null ? (float)$subscription->primer_cobro_monto : null,
            'primer_cobro_desde'=>$subscription->primer_cobro_desde?->format('Y-m-d'),
            'primer_cobro_hasta'=>$subscription->primer_cobro_hasta?->format('Y-m-d'),
            'primer_cobro_pagado'=>(bool)$subscription->primer_cobro_pagado,
            'fecha_vencimiento'=>$subscription->fecha_vencimiento?->format('Y-m-d'),
            'dias_gracia'=>(int)$subscription->dias_gracia,
            'gracia_hasta'=>$graceEnd?->format('Y-m-d'),
            'dias_para_vencer'=>$daysToDue,
            'dias_mora'=>$daysLate,
            'estado'=>$subscription->estado,
            'etapa_cobro'=>$stage,
            'requiere_pago'=>!$inTrial && $subscription->estado !== 'cancelada',
            'puede_usar'=>$canUse,
            'mensaje_cobro'=>$this->message($stage,$daysToDue,$daysLate,$graceEnd?->format('Y-m-d')),
        ];
    }

    public function assertCanUse(Aplicacion $app): void
    {
        $status = $this->statusFor($app);
        if (!$status) return;

        abort_unless($status['puede_usar'], 402, 'Tu suscripción VITI no está habilitada para usar la aplicación. Regulariza el estado de la suscripción para continuar.');
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
            'cancelada' => 'La suscripción está cancelada y el acceso permanece cerrado.',
            default => 'La suscripción está al día.',
        };
    }
}
