<?php

namespace App\Http\Controllers;

use App\Models\Aplicacion;
use App\Services\SubscriptionAccessService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationAccessController extends Controller
{
    public function status(Aplicacion $aplicacion, SubscriptionAccessService $access): JsonResponse
    {
        return response()->json([
            'data' => [
                'aplicacion_id' => $aplicacion->id,
                'nombre' => $aplicacion->nombre,
                'estado_operacion' => $aplicacion->estado,
                'acceso_cliente' => (bool) $aplicacion->acceso_cliente,
                'suscripcion' => $access->statusFor($aplicacion),
            ],
        ]);
    }

    public function block(Request $request, Aplicacion $aplicacion, SubscriptionAccessService $access): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $updated = $access->blockManually($aplicacion, (int) $request->user()->id, $data['motivo']);
        Audit::log($request, 'aplicacion_bloqueada_manual', $updated, 'Se bloqueó manualmente el acceso operativo de la aplicación.');

        return response()->json([
            'message' => 'Aplicación bloqueada manualmente.',
            'data' => $access->statusFor($updated),
        ]);
    }

    public function unblock(Request $request, Aplicacion $aplicacion, SubscriptionAccessService $access): JsonResponse
    {
        $updated = $access->unblockManually($aplicacion);
        Audit::log($request, 'aplicacion_desbloqueada_manual', $updated, 'Se retiró el bloqueo manual de la aplicación.');

        return response()->json([
            'message' => 'Bloqueo manual retirado.',
            'data' => $access->statusFor($updated),
        ]);
    }
}
