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
            'acceso.required'=>'Ingresa tu usuario, teléfono o número de CI.',
            'password.required'=>'Ingresa tu contraseña.',
        ]);

        $access = trim($data['acceso']);
        $username = Str::lower($access);
        $phoneDigits = preg_replace('/\D+/', '', $access);
        $numericAccess = preg_match('/^[0-9\s()+.-]+$/', $access) && strlen($phoneDigits) >= 5
            ? $phoneDigits
            : null;

        $usuario = Usuario::query()
            ->where('estado','activo')
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
            return response()->json(['message'=>'El usuario, teléfono, CI o contraseña no son correctos.'], 422);
        }

        if ($usuario->isSuperAdmin()) {
            $expected = (string) config('app.admin_secret');
            if ($expected === '') return response()->json(['message'=>'El servidor no tiene configurado el código secreto administrativo.'],500);
            if (!hash_equals($expected, (string)($data['codigo_secreto'] ?? ''))) {
                $this->auditFailedLogin($request, $usuario, 'codigo_administrativo_incorrecto', $access);
                return response()->json(['message'=>'El código secreto administrativo es incorrecto.'], 422);
            }
        }

        // Si los parámetros de hash cambian en el futuro, el próximo acceso correcto
        // actualiza la contraseña sin pedirle nada adicional al usuario.
        if (Hash::needsRehash($usuario->password)) {
            $usuario->forceFill(['password' => $data['password']])->save();
        }

        // El login web de VITI necesita una sesión stateful de Sanctum. Si un
        // frontend autorizado por CORS no fue reconocido como stateful por una
        // configuración desalineada, no intentamos regenerar una sesión inexistente:
        // devolvemos un error controlado en vez de provocar un 500.
        if (!$request->hasSession()) {
            return response()->json([
                'message'=>'No pudimos establecer la sesión segura de VITI. Actualiza la página e inténtalo nuevamente.',
            ], 419);
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
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        return response()->json(['message'=>'Sesión cerrada correctamente.']);
    }

    private function auditFailedLogin(Request $request, ?Usuario $usuario, string $reason, string $access): void
    {
        Audit::log(
            $request,
            'inicio_sesion_fallido',
            $usuario,
            'Se rechazó un intento de inicio de sesión.',
            [
                'motivo' => $reason,
                // No almacenamos CI, teléfono ni usuario escrito en texto plano.
                'identificador_hash' => hash('sha256', Str::lower(trim($access))),
            ]
        );
    }
}
