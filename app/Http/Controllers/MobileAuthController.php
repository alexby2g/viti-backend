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
        ], [
            'acceso.required' => 'Ingresa tu usuario, teléfono o número de CI.',
            'password.required' => 'Ingresa tu contraseña.',
        ]);

        $access = trim($data['acceso']);
        $username = Str::lower($access);
        $phoneDigits = preg_replace('/\D+/', '', $access);
        $numericAccess = preg_match('/^[0-9\s()+.-]+$/', $access) && strlen($phoneDigits) >= 5
            ? $phoneDigits
            : null;

        $usuario = Usuario::query()
            ->where('estado', 'activo')
            ->where('rol', 'cliente')
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
            return response()->json(['message' => 'El usuario, teléfono, CI o contraseña no son correctos.'], 422);
        }

        $usuario->tokens()->where('name', 'viti-mobile')->delete();
        $token = $usuario->createToken('viti-mobile', ['cliente'], now()->addDays(30))->plainTextToken;
        $usuario->forceFill(['ultimo_acceso' => now()])->save();
        Audit::log($request, 'inicio_sesion_movil', $usuario, 'Inicio de sesión desde VITI Móvil.');

        return response()->json([
            'token' => $token,
            'tipo' => 'Bearer',
            'expira_en_dias' => 30,
            'usuario' => $usuario->loadMissing('cliente'),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }
        return response()->json(['message' => 'Sesión móvil cerrada correctamente.']);
    }
}
