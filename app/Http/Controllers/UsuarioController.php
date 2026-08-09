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
        if (empty($data['password'])) unset($data['password']);
        $usuario->update($data);
        Audit::log($request, 'usuario_actualizado', $usuario, 'Se actualizó un usuario interno.');
        return response()->json(['data'=>$usuario]);
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
        return $request->validate([
            'nombre'=>['required','string','max:120'],
            'apellido'=>['nullable','string','max:120'],
            'usuario'=>['required','alpha_dash','min:4','max:80','not_regex:/^\d+$/',Rule::unique('usuarios','usuario')->ignore($usuario?->id)],
            'telefono'=>['nullable','string','max:30',Rule::unique('usuarios','telefono')->ignore($usuario?->id)],
            'correo'=>['nullable','email','max:160',Rule::unique('usuarios','correo')->ignore($usuario?->id)],
            'password'=>$usuario ? ['nullable','confirmed',Password::min(12)->letters()->mixedCase()->numbers()] : ['required','confirmed',Password::min(12)->letters()->mixedCase()->numbers()],
            'rol'=>['required',Rule::in(['superadmin','administrador','soporte'])],
            'estado'=>['nullable',Rule::in(['activo','inactivo'])],
        ]);
    }

    private function normalizeUsername(Request $request): void
    {
        $request->merge(['usuario' => Str::lower(trim((string) $request->input('usuario')))]);
    }
}
