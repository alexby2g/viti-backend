<?php

namespace App\Services;

use App\Models\Aplicacion;
use App\Models\Suscripcion;

class SubscriptionAccessService
{
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
        $inTrial = $trialEnd && $today->lte($trialEnd);

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
            'estado'=>$subscription->estado,
            'puede_usar'=>$inTrial || in_array($subscription->estado,['activa','gracia'],true),
        ];
    }

    public function assertCanUse(Aplicacion $app): void
    {
        $status = $this->statusFor($app);
        if (!$status) return;

        abort_unless($status['puede_usar'], 402, 'Tu suscripción VITI está suspendida. Regulariza el pago para volver a utilizar la aplicación.');
    }
}
