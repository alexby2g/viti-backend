<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'authenticated' => (bool) $request->user(),
            'usuario' => $request->user()?->loadMissing('cliente'),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'acceso' => ['required','string','max:160'],
            'password' => ['required','string'],
            'codigo_secreto' => ['nullable','string','max:120'],
        ], [
            'acceso.required'=>'Ingresa tu usuario o número de teléfono.',
            'password.required'=>'Ingresa tu contraseña.',
        ]);

        $usuario = Usuario::query()
            ->where('estado','activo')
            ->where(fn ($q) => $q->where('usuario',$data['acceso'])->orWhere('telefono',$data['acceso']))
            ->first();

        if (!$usuario || !Hash::check($data['password'], $usuario->password)) {
            return response()->json(['message'=>'El usuario, teléfono o contraseña no son correctos.'], 422);
        }

        if ($usuario->isSuperAdmin()) {
            $expected = (string) config('app.admin_secret');
            if ($expected === '') return response()->json(['message'=>'El servidor no tiene configurado el código secreto administrativo.'],500);
            if (!hash_equals($expected, (string)($data['codigo_secreto'] ?? ''))) {
                return response()->json(['message'=>'El código secreto administrativo es incorrecto.'], 422);
            }
        }

        auth()->login($usuario, false);
        $request->session()->regenerate();
        $usuario->forceFill(['ultimo_acceso'=>now()])->save();
        Audit::log($request, 'inicio_sesion', $usuario, 'Inicio de sesión correcto.');

        return response()->json(['usuario'=>$usuario->loadMissing('cliente')]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['usuario'=>$request->user()->loadMissing('cliente')]);
    }

    public function logout(Request $request): JsonResponse
    {
        Audit::log($request, 'cierre_sesion', $request->user(), 'Cierre de sesión.');
        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message'=>'Sesión cerrada correctamente.']);
    }
}
