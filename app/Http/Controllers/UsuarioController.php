<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UsuarioController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Usuario::query()->latest()->paginate(30));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->data($request);
        $usuario = Usuario::create($data);
        Audit::log($request, 'usuario_creado', $usuario, 'Se creó un usuario interno de VITI.');
        return response()->json(['data'=>$usuario], 201);
    }

    public function update(Request $request, Usuario $usuario): JsonResponse
    {
        $data = $this->data($request, $usuario);
        if (empty($data['password'])) unset($data['password']);
        $usuario->update($data);
        Audit::log($request, 'usuario_actualizado', $usuario, 'Se actualizó un usuario interno.');
        return response()->json(['data'=>$usuario]);
    }

    private function data(Request $request, ?Usuario $usuario=null): array
    {
        return $request->validate([
            'nombre'=>['required','string','max:120'],
            'apellido'=>['nullable','string','max:120'],
            'usuario'=>['required','alpha_dash','max:80',Rule::unique('usuarios','usuario')->ignore($usuario?->id)],
            'telefono'=>['nullable','string','max:30',Rule::unique('usuarios','telefono')->ignore($usuario?->id)],
            'correo'=>['nullable','email','max:160',Rule::unique('usuarios','correo')->ignore($usuario?->id)],
            'password'=>$usuario ? ['nullable','confirmed',Password::min(12)->letters()->mixedCase()->numbers()] : ['required','confirmed',Password::min(12)->letters()->mixedCase()->numbers()],
            'rol'=>['required',Rule::in(['superadmin','administrador','soporte'])],
            'estado'=>['nullable',Rule::in(['activo','inactivo'])],
        ]);
    }
}
