<?php

namespace App\Services;

use App\Models\Aplicacion;

class AppLifecycleService
{
    public function __construct(private SubscriptionAccessService $subscriptions) {}

    public function status(Aplicacion $app): array
    {
        if ($app->estado === 'retirado') return ['estado'=>'cancelada','puede_usar'=>false,'mensaje'=>'La aplicación fue retirada.'];
        if ($app->estado === 'pausado') return ['estado'=>'suspendida','puede_usar'=>false,'mensaje'=>'La aplicación está pausada.'];
        if ($app->entorno === 'desarrollo') return ['estado'=>'preparacion','puede_usar'=>false,'mensaje'=>'La aplicación está en preparación.'];
        if ($app->entorno === 'beta' || $app->estado === 'en_pruebas') return ['estado'=>'pruebas','puede_usar'=>false,'mensaje'=>'La aplicación está en pruebas.'];
        if ($app->entorno === 'produccion' && $app->estado === 'activo' && !$app->acceso_cliente) {
            return ['estado'=>'lista_entrega','puede_usar'=>false,'mensaje'=>'La aplicación está lista para entregar.'];
        }

        if ($app->entorno === 'produccion' && $app->estado === 'activo' && $app->acceso_cliente) {
            $subscription = $this->subscriptions->statusFor($app);
            if (!$subscription) return ['estado'=>'activa','puede_usar'=>true,'mensaje'=>'Aplicación activa.'];
            return [
                'estado'=>$subscription['estado'],
                'puede_usar'=>(bool)$subscription['puede_usar'],
                'mensaje'=>$subscription['estado']==='gracia' ? 'Pago pendiente dentro del periodo de gracia.' : ($subscription['estado']==='suspendida' ? 'Suscripción suspendida.' : 'Aplicación activa.'),
                'suscripcion'=>$subscription,
            ];
        }

        return ['estado'=>'preparacion','puede_usar'=>false,'mensaje'=>'La aplicación todavía no está disponible.'];
    }
}
