<?php

namespace App\Http\Controllers;

use App\Models\{Cliente, Empresa, IrenetDevice, Mantenimiento, Proyecto, SolicitudSistema};
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IrenetConnectionController extends Controller
{
    /**
     * List linked devices, never their credentials.
     * Only platform superadministrators can manage a pairing.
     */
    public function devices(Request $request): JsonResponse
    {
        $devices = IrenetDevice::query()
            ->where('usuario_id', $request->user()->id)
            ->latest('id')
            ->get(['id', 'nombre', 'expira_at', 'revocado_at', 'ultimo_acceso_at', 'created_at']);

        return response()->json(['data' => $devices])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Mint a dedicated, read-only credential. It is shown only once.
     * Never log or store the raw token.
     */
    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:80'],
        ]);

        $activeDevices = IrenetDevice::query()
            ->where('usuario_id', $request->user()->id)
            ->whereNull('revocado_at')
            ->where('expira_at', '>', now())
            ->count();

        abort_if($activeDevices >= 5, 422, 'Has alcanzado el máximo de cinco dispositivos activos.');

        $plainToken = 'ivt_'.bin2hex(random_bytes(32));
        $device = IrenetDevice::create([
            'usuario_id' => $request->user()->id,
            'nombre' => trim($data['nombre']),
            'token_hash' => hash('sha256', $plainToken),
            'expira_at' => now()->addDays(14),
        ]);

        Audit::log(
            $request,
            'irenet_dispositivo_vinculado',
            $device,
            'Se vinculó un dispositivo IRENET de solo lectura.'
        );

        return response()->json([
            'data' => [
                'id' => $device->id,
                'nombre' => $device->nombre,
                'expira_at' => $device->expira_at?->toIso8601String(),
                'token' => $plainToken,
                'aviso' => 'Copia el token ahora: no volverá a mostrarse.',
            ],
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, IrenetDevice $device): JsonResponse
    {
        abort_unless((int) $device->usuario_id === (int) $request->user()->id, 404);

        if ($device->revocado_at === null) {
            $device->update(['revocado_at' => now()]);
            Audit::log(
                $request,
                'irenet_dispositivo_revocado',
                $device,
                'Se revocó el dispositivo IRENET.'
            );
        }

        return response()->json(['message' => 'El acceso del dispositivo está revocado.'])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Called directly from IRENET Windows over HTTPS, never with a web cookie.
     * This isolated credential cannot authenticate any other VITI endpoint.
     */
    public function context(Request $request): JsonResponse
    {
        $token = (string) $request->bearerToken();
        abort_unless(
            Str::startsWith($token, 'ivt_')
                && strlen($token) === 68
                && ctype_xdigit(substr($token, 4)),
            401,
            'Credencial de dispositivo no válida.'
        );

        $device = IrenetDevice::with('usuario')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        abort_unless(
            $device
                && $device->revocado_at === null
                && $device->expira_at?->isFuture()
                && $device->usuario?->isSuperAdmin()
                && $device->usuario?->estado === 'activo',
            401,
            'Credencial de dispositivo no válida.'
        );

        if ($device->ultimo_acceso_at === null || $device->ultimo_acceso_at->lt(now()->subMinutes(10))) {
            $device->update(['ultimo_acceso_at' => now()]);
        }

        return response()->json([
            'data' => [
                'tipo' => 'resumen_operativo',
                'solo_lectura' => true,
                'consultado_at' => now()->toIso8601String(),
                'metricas' => [
                    'clientes' => Cliente::count(),
                    'empresas' => Empresa::count(),
                    'solicitudes_en_revision' => SolicitudSistema::where('estado', 'en_revision')->count(),
                    'proyectos_activos' => Proyecto::where('estado', 'activo')->count(),
                    'soportes_abiertos' => Mantenimiento::whereNotIn('estado', ['resuelto', 'cerrado'])->count(),
                ],
            ],
        ])->header('Cache-Control', 'no-store')
            ->header('Vary', 'Authorization');
    }
}
