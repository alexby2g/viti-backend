<?php

namespace App\Observers;

use App\Models\Empresa;
use App\Models\Suscripcion;
use Illuminate\Support\Carbon;

class SuscripcionObserver
{
    public function saving(Suscripcion $suscripcion): void
    {
        if (!$suscripcion->empresa_id || !in_array($suscripcion->frecuencia, ['mensual','anual'], true)) return;

        $empresa = Empresa::query()->with('planViti')->find($suscripcion->empresa_id);
        $plan = $empresa?->planViti;
        if (!$plan) return;

        $price = $suscripcion->frecuencia === 'anual' ? $plan->precio_anual : $plan->precio_mensual;
        if ($price === null) return;

        $suscripcion->plan = $plan->nombre;
        $suscripcion->monto = (float) $price;

        if ($suscripcion->frecuencia === 'anual') {
            $start = $suscripcion->prueba_hasta
                ? Carbon::parse($suscripcion->prueba_hasta)->startOfDay()->addDay()
                : Carbon::parse($suscripcion->fecha_inicio)->startOfDay();

            $suscripcion->primer_cobro_monto = (float) $price;
            $suscripcion->primer_cobro_desde = $start->toDateString();
            $suscripcion->primer_cobro_hasta = $start->copy()->addYear()->subDay()->toDateString();

            if (!$suscripcion->primer_cobro_pagado) {
                $suscripcion->fecha_vencimiento = $start->toDateString();
            }
            return;
        }

        if (!$suscripcion->primer_cobro_desde || !$suscripcion->primer_cobro_hasta) return;

        $from = Carbon::parse($suscripcion->primer_cobro_desde)->startOfDay();
        $to = Carbon::parse($suscripcion->primer_cobro_hasta)->startOfDay();
        $billableDays = $from->diffInDays($to) + 1;
        $suscripcion->primer_cobro_monto = round(((float) $price * $billableDays) / $from->daysInMonth, 2);
    }
}
