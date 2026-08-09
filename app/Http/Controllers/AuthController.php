<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

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

        $access = trim($data['acceso']);
        $username = Str::lower($access);
        $phone = preg_replace('/\D+/', '', $access);

        $usuario = Usuario::query()
            ->where('estado','activo')
            ->where(fn ($q) => $q->whereRaw('LOWER(usuario) = ?', [$username])->orWhere('telefono',$phone))
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

    public function uploadPhoto(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Solo el superadministrador puede cambiar esta fotografía.');
        $request->validate(['foto'=>['required','image','mimes:jpg,jpeg,png,webp','max:3072']], [
            'foto.required'=>'Selecciona una fotografía.',
            'foto.image'=>'El archivo debe ser una imagen válida.',
            'foto.mimes'=>'La fotografía debe ser JPG, PNG o WEBP.',
            'foto.max'=>'La fotografía no puede superar 3 MB.',
        ]);

        $usuario = $request->user();
        $disk = Storage::disk('public');
        $oldPath = $usuario->foto_path;

        try {
            $path = $request->file('foto')->store('usuarios/fotos','public');
            if (!$path || !$disk->exists($path)) {
                throw new \RuntimeException('R2 no confirmó la fotografía del superadministrador.');
            }

            $usuario->update(['foto_path'=>$path]);

            if ($oldPath && $oldPath !== $path) {
                try { $disk->delete($oldPath); } catch (Throwable) {}
            }

            Audit::log($request, 'foto_perfil_actualizada', $usuario, 'El superadministrador actualizó su fotografía de perfil.');
            return response()->json([
                'message'=>'Fotografía actualizada correctamente.',
                'usuario'=>$usuario->fresh()->loadMissing('cliente'),
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'message'=>'No pudimos guardar tu fotografía en el almacenamiento permanente. Revisa Administración → Almacenamiento.',
            ], 503);
        }
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
