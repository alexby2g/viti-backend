<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class SetupController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json(['requiere_configuracion' => !Usuario::query()->exists()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_if(Usuario::query()->exists(), 409, 'La configuración inicial ya fue completada.');
        $request->merge(['usuario' => Str::lower(trim((string) $request->input('usuario')))]);

        $data = $request->validate([
            'codigo_secreto' => ['required', 'string', 'max:120'],
            'nombre' => ['required', 'string', 'max:120'],
            'apellido' => ['nullable', 'string', 'max:120'],
            'usuario' => ['required', 'string', 'alpha_dash', 'min:4', 'max:80', 'not_regex:/^\d+$/', 'unique:usuarios,usuario'],
            'telefono' => ['required', 'regex:/^[0-9]{7,15}$/', 'unique:usuarios,telefono'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()],
        ], [
            'codigo_secreto.required' => 'Ingresa el código secreto de instalación.',
            'nombre.required' => 'El nombre es obligatorio.',
            'usuario.required' => 'El usuario es obligatorio.',
            'usuario.alpha_dash' => 'El usuario solo puede contener letras, números, guiones y guiones bajos.',
            'usuario.min' => 'El usuario debe tener al menos 4 caracteres.',
            'usuario.unique' => 'Ese nombre de usuario ya está registrado.',
            'telefono.required' => 'El teléfono es obligatorio.',
            'telefono.regex' => 'El teléfono debe contener entre 7 y 15 dígitos.',
            'telefono.unique' => 'Ese número ya se encuentra registrado.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        $expected = (string) config('app.setup_secret');
        abort_if($expected === '', 500, 'El servidor no tiene configurado el código secreto de instalación.');
        abort_unless(hash_equals($expected, (string) $data['codigo_secreto']), 403, 'Código secreto incorrecto.');

        unset($data['codigo_secreto']);

        $usuario = DB::transaction(fn () => Usuario::create($data + [
            'rol' => 'superadmin',
            'estado' => 'activo',
        ]));

        auth()->login($usuario);
        $request->session()->regenerate();
        Audit::log($request, 'setup_completado', $usuario, 'Se creó el administrador único de VITI.');

        return response()->json(['usuario' => $usuario], 201);
    }
}
