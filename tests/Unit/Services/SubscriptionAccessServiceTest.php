<?php

use App\Models\Aplicacion;
use App\Models\Suscripcion;
use App\Services\SubscriptionAccessService;

it('manual application block overrides an otherwise usable subscription', function (): void {
    $app = new Aplicacion([
        'estado' => 'activo',
        'acceso_bloqueado_manual' => true,
    ]);
    $subscription = new Suscripcion([
        'estado' => 'activa',
        'monto' => 100,
        'frecuencia' => 'mensual',
        'moneda' => 'BOB',
        'fecha_inicio' => now()->toDateString(),
        'fecha_vencimiento' => now()->addMonth()->toDateString(),
        'dias_gracia' => 3,
    ]);
    $app->setRelation('suscripcion', $subscription);

    $service = app(SubscriptionAccessService::class);
    $status = $service->statusFor($app);

    expect($status['bloqueado_manual'])->toBeTrue()
        ->and($status['puede_usar'])->toBeFalse();
});
