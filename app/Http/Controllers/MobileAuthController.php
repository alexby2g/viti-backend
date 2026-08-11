<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MobileAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'acceso' => ['required','string','max:160'],
            'password' => ['required','string'],
            'codigo_secreto' => ['nullable','string','max:120'],
            'device_id' => ['required','string','min:8','max:120'],
            'device_name' => ['required','string','max:120'],
            'platform' => ['required','in:android,windows,ios,macos,linux'],
        ], [
            'acceso.required' => 'Ingresa tu usuario, teléfono o número de CI.',
            'password.required' => 'Ingresa tu contraseña.',
            'device_id.required' => 'No pudimos identificar este dispositivo.',
            'device_name.required' => 'No pudimos identificar el nombre de este dispositivo.',
            'platform.required' => 'No pudimos identificar la plataforma.',
            'platform.in' => 'La plataforma enviada no es compatible con VITI.',
        ]);

        $access = trim($data['acceso']);
        $username = Str::lower($access);
        $phoneDigits = preg_replace('/\D+/', '', $access);
        $numericAccess = preg_match('/^[0-9\s()+.-]+$/', $access) && strlen($phoneDigits) >= 5
            ? $phoneDigits
            : null;

        $usuario = Usuario::query()
            ->where('estado', 'activo')
            ->whereIn('rol', ['cliente','administrador','superadmin','soporte'])
            ->where(function ($query) use ($username, $numericAccess): void {
                $query->whereRaw('LOWER(usuario) = ?', [$username]);
                if ($numericAccess !== null) {
                    $query->orWhere('telefono', $numericAccess)
                        ->orWhere('documento', $numericAccess)
                        ->orWhereHas('cliente', fn ($client) => $client->where('documento', $numericAccess));
                }
            })
            ->first();

        if (!$usuario || !Hash::check($data['password'], $usuario->password)) {
            $this->auditFailedLogin($request, $usuario, 'credenciales_invalidas', $access);
            return response()->json(['message' => 'El usuario, teléfono, CI o contraseña no son correctos.'], 422);
        }

        if ($usuario->isSuperAdmin()) {
            $expected = (string) config('app.admin_secret');
            if ($expected === '') {
                return response()->json(['message'=>'El servidor no tiene configurado el código secreto administrativo.'],500);
            }
            if (!hash_equals($expected, (string) ($data['codigo_secreto'] ?? ''))) {
                $this->auditFailedLogin($request, $usuario, 'codigo_administrativo_incorrecto', $access);
                return response()->json(['message'=>'El código secreto administrativo es incorrecto.'],422);
            }
        }

        if (Hash::needsRehash($usuario->password)) {
            $usuario->forceFill(['password' => $data['password']])->save();
        }

        $deviceId = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', trim($data['device_id']));
        $tokenName = 'viti-native:'.$deviceId;

        // Solo rota la sesión del mismo dispositivo. Android y Windows pueden
        // permanecer conectados simultáneamente con tokens independientes.
        $usuario->tokens()->where('name', $tokenName)->delete();

        $token = $usuario->createToken(
            $tokenName,
            ['viti-native', 'role:'.$usuario->rol, 'platform:'.$data['platform']],
            now()->addDays(30)
        )->plainTextToken;

        $usuario->forceFill(['ultimo_acceso' => now()])->save();
        Audit::log($request, 'inicio_sesion_nativo', $usuario, 'Inicio de sesión desde VITI nativo.', [
            'platform' => $data['platform'],
            'device_name' => Str::limit(trim($data['device_name']), 80, ''),
            'device_hash' => hash('sha256', $deviceId),
        ]);

        return response()->json([
            'token' => $token,
            'tipo' => 'Bearer',
            'expira_en_dias' => 30,
            'usuario' => $usuario->loadMissing('cliente'),
            'sesion' => [
                'platform' => $data['platform'],
                'device_name' => trim($data['device_name']),
            ],
        ]);
    }

    public function version(Request $request): JsonResponse
    {
        $platform = Str::lower((string) $request->query('platform', 'android'));
        abort_unless(in_array($platform, ['android','windows','ios','macos','linux'], true), 422, 'Plataforma no compatible.');

        $release = (array) config('native.'.$platform, []);
        $latest = (string) ($release['latest'] ?? '0.1.0');
        $minimum = (string) ($release['minimum'] ?? '0.1.0');
        $current = trim((string) $request->query('version', ''));

        return response()->json([
            'platform' => $platform,
            'latest' => $latest,
            'minimum' => $minimum,
            'update_url' => $release['url'] ?? null,
            'update_available' => $current !== '' ? version_compare($current, $latest, '<') : false,
            'update_required' => $current !== '' ? version_compare($current, $minimum, '<') : false,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }
        return response()->json(['message' => 'Sesión nativa cerrada correctamente.']);
    }

    private function auditFailedLogin(Request $request, ?Usuario $usuario, string $reason, string $access): void
    {
        Audit::log($request, 'inicio_sesion_nativo_fallido', $usuario, 'Se rechazó un acceso desde VITI nativo.', [
            'motivo' => $reason,
            'identificador_hash' => hash('sha256', Str::lower(trim($access))),
        ]);
    }
}
