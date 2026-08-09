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
        $data = $this->data($request);
        $usuario = Usuario::create($data);
        Audit::log($request, 'usuario_creado', $usuario, 'Se creó un usuario interno de VITI.');
        return response()->json(['data'=>$usuario], 201);
    }

    public function update(Request $request, Usuario $usuario): JsonResponse
    {
        $this->normalizeUsername($request);
        $data = $this->data($request, $usuario);

        if ($usuario->isSuperAdmin()) {
            $data['rol'] = 'superadmin';
            $data['estado'] = 'activo';
        } elseif ($usuario->cliente_id || $usuario->rol === 'cliente') {
            $data['rol'] = 'cliente';
        }

        if (empty($data['password'])) unset($data['password']);
        $usuario->update($data);
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

        return $request->validate([
            'nombre'=>['required','string','max:120'],
            'apellido'=>['nullable','string','max:120'],
            'usuario'=>['required','alpha_dash','min:4','max:80','not_regex:/^\d+$/',Rule::unique('usuarios','usuario')->ignore($usuario?->id)],
            'telefono'=>['nullable','string','max:30',Rule::unique('usuarios','telefono')->ignore($usuario?->id)],
            'correo'=>['nullable','email','max:160',Rule::unique('usuarios','correo')->ignore($usuario?->id)],
            'password'=>$usuario ? ['nullable','confirmed',Password::min(12)->letters()->mixedCase()->numbers()] : ['required','confirmed',Password::min(12)->letters()->mixedCase()->numbers()],
            'rol'=>$roleRules,
            'estado'=>$stateRules,
        ], [
            'rol.in' => $protectedRole === 'cliente'
                ? 'Una cuenta de cliente no puede convertirse en administrador.'
                : ($protectedRole === 'superadmin'
                    ? 'La cuenta principal debe conservar el rol de superadministrador.'
                    : 'Solo puedes crear usuarios internos con rol administrador o soporte.'),
            'estado.in' => $protectedRole === 'superadmin'
                ? 'La cuenta principal del superadministrador debe permanecer activa.'
                : 'Selecciona un estado válido.',
        ]);
    }

    private function normalizeUsername(Request $request): void
    {
        $request->merge(['usuario' => Str::lower(trim((string) $request->input('usuario')))]);
    }
}
