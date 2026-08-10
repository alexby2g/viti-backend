<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\AccountDeletionService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UsuarioController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Usuario::query()
            ->with(['cliente:id,nombre,telefono', 'negocios:id,nombre_comercial'])
            ->latest()->paginate(30));
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeUsername($request);
        $this->verifyAdminSecret($request);
        $data = $this->data($request);
        unset($data['codigo_secreto']);
        $usuario = Usuario::create($data);
        Audit::log($request, 'usuario_creado', $usuario, 'Se creó un usuario interno de VITI.');
        return response()->json(['data'=>$usuario], 201);
    }

    public function update(Request $request, Usuario $usuario): JsonResponse
    {
        $this->normalizeUsername($request);
        $data = $this->data($request, $usuario);
        unset($data['codigo_secreto']);

        if ($usuario->isSuperAdmin()) {
            $data['rol'] = 'superadmin';
            $data['estado'] = 'activo';
        } elseif ($usuario->cliente_id || $usuario->rol === 'cliente') {
            $data['rol'] = 'cliente';
        }

        if (empty($data['password'])) unset($data['password']);
        $usuario->update($data);
        if ($usuario->cliente_id && array_key_exists('documento', $data) && $data['documento']) {
            $usuario->cliente()->update(['documento' => $data['documento']]);
        }
        Audit::log($request, 'usuario_actualizado', $usuario, 'Se actualizó una cuenta sin modificar su tipo de acceso protegido.');
        return response()->json(['data'=>$usuario->fresh()]);
    }

    public function destroy(Request $request, Usuario $usuario, AccountDeletionService $deletion): JsonResponse
    {
        abort_if((int) $request->user()->id === (int) $usuario->id, 422, 'No puedes eliminar la cuenta con la que tienes la sesión iniciada.');
        abort_if($usuario->isSuperAdmin(), 422, 'La cuenta principal del superadministrador no se puede eliminar.');

        $username = $usuario->usuario;
        $summary = $deletion->deleteUser($usuario);
        Audit::log($request, 'usuario_eliminado_cascada', null, 'Se eliminó la cuenta '.$username.' y sus datos dependientes.', $summary);

        return response()->json([
            'message' => 'La cuenta y sus datos relacionados fueron eliminados correctamente.',
            'data' => $summary,
        ]);
    }

    private function data(Request $request, ?Usuario $usuario=null): array
    {
        $protectedRole = $usuario?->isSuperAdmin()
            ? 'superadmin'
            : (($usuario?->cliente_id || $usuario?->rol === 'cliente') ? 'cliente' : null);

        $roleRules = $protectedRole
            ? ['required', Rule::in([$protectedRole])]
            : ['required', Rule::in(['administrador','soporte'])];

        $stateRules = $usuario?->isSuperAdmin()
            ? ['required', Rule::in(['activo'])]
            : ['nullable', Rule::in(['activo','inactivo'])];

        $documentRules = $usuario
            ? ['nullable','regex:/^[0-9]{5,15}$/',Rule::unique('usuarios','documento')->ignore($usuario->id)]
            : ['required','regex:/^[0-9]{5,15}$/',Rule::unique('usuarios','documento')];
        if ($usuario?->cliente_id) $documentRules[] = Rule::unique('clientes','documento')->ignore($usuario->cliente_id);

        return $request->validate([
            'nombre'=>['required','string','max:120'],
            'apellido'=>['nullable','string','max:120'],
            'usuario'=>['required','alpha_dash','min:4','max:80','not_regex:/^\d+$/',Rule::unique('usuarios','usuario')->ignore($usuario?->id)],
            'documento'=>$documentRules,
            'telefono'=>['nullable','string','max:30',Rule::unique('usuarios','telefono')->ignore($usuario?->id)],
            'correo'=>['nullable','email','max:160',Rule::unique('usuarios','correo')->ignore($usuario?->id)],
            'password'=>$usuario ? ['nullable','confirmed',Password::min(12)->letters()->mixedCase()->numbers()] : ['required','confirmed',Password::min(12)->letters()->mixedCase()->numbers()],
            'rol'=>$roleRules,
            'estado'=>$stateRules,
            'codigo_secreto'=>['nullable','string','max:120'],
        ], [
            'rol.in' => $protectedRole === 'cliente'
                ? 'Una cuenta de cliente no puede convertirse en administrador.'
                : ($protectedRole === 'superadmin'
                    ? 'La cuenta principal debe conservar el rol de superadministrador.'
                    : 'Solo puedes crear usuarios internos con rol administrador o soporte.'),
            'estado.in' => $protectedRole === 'superadmin'
                ? 'La cuenta principal del superadministrador debe permanecer activa.'
                : 'Selecciona un estado válido.',
            'documento.required' => 'Ingresa el CI del nuevo administrador.',
            'documento.regex' => 'El CI debe contener entre 5 y 15 dígitos.',
            'documento.unique' => 'Ese número de CI ya está asignado a otra cuenta.',
        ]);
    }

    private function normalizeUsername(Request $request): void
    {
        $request->merge([
            'usuario' => Str::lower(trim((string) $request->input('usuario'))),
            'documento' => preg_replace('/\D+/', '', (string) $request->input('documento')) ?: null,
        ]);
    }

    private function verifyAdminSecret(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Solo el superadministrador puede crear cuentas internas.');
        $expected = (string) config('app.admin_secret');
        abort_if($expected === '', 500, 'El servidor no tiene configurado el código secreto administrativo.');
        abort_unless(hash_equals($expected, (string) $request->input('codigo_secreto')), 422, 'El código secreto administrativo es incorrecto.');
    }
}
