<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Usuario;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Throwable;

class ClientAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->merge(['usuario' => Str::lower(trim((string) $request->input('usuario')))]);
        $data = $request->validate([
            'nombre' => ['required','string','max:120'],
            'usuario' => ['required','string','alpha_dash','min:4','max:40','not_regex:/^\d+$/',Rule::unique('usuarios','usuario')],
            'telefono' => ['required','regex:/^[0-9]{7,15}$/','unique:clientes,telefono','unique:usuarios,telefono'],
            'whatsapp' => ['nullable','regex:/^[0-9]{7,15}$/'],
            'ci' => ['required','string','max:50','unique:clientes,documento'],
            'ci_expedido' => ['nullable','string','max:20'],
            'foto' => ['required','image','mimes:jpg,jpeg,png,webp','max:4096'],
            'ciudad' => ['required','string','max:100'],
            'direccion' => ['nullable','string','max:255'],
            'password' => ['required','confirmed', Password::min(10)->letters()->numbers()],
            'canal_origen' => ['nullable','in:viti,agr_studio,referido,whatsapp,otro'],
        ], [
            'nombre.required' => 'Ingresa tu nombre completo.',
            'usuario.required' => 'Crea un nombre de usuario para ingresar a VITI.',
            'usuario.alpha_dash' => 'El usuario solo puede contener letras, números, guiones y guiones bajos.',
            'usuario.min' => 'El usuario debe tener al menos 4 caracteres.',
            'usuario.not_regex' => 'El usuario debe incluir al menos una letra.',
            'usuario.unique' => 'Ese nombre de usuario ya está registrado.',
            'telefono.required' => 'Ingresa tu número de teléfono.',
            'telefono.regex' => 'El teléfono debe contener entre 7 y 15 dígitos.',
            'telefono.unique' => 'Ese número de teléfono ya está registrado.',
            'whatsapp.regex' => 'El número de WhatsApp debe contener entre 7 y 15 dígitos.',
            'ci.required' => 'Ingresa tu número de cédula de identidad.',
            'ci.unique' => 'Ese número de cédula ya está registrado.',
            'foto.required' => 'Sube una fotografía de perfil donde se vea claramente tu rostro.',
            'foto.image' => 'La fotografía de perfil debe ser una imagen válida.',
            'foto.max' => 'La fotografía no puede superar 4 MB.',
            'ciudad.required' => 'Indica la ciudad o localidad donde vives.',
            'password.required' => 'Crea una contraseña para ingresar a VITI.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        try {
            $photoPath = $request->file('foto')->store('clientes/fotos','public');
            if (!$photoPath || !Storage::disk('public')->exists($photoPath)) {
                throw new \RuntimeException('R2 no confirmó la fotografía del nuevo cliente.');
            }
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'message'=>'No pudimos guardar tu fotografía en el almacenamiento permanente. Inténtalo nuevamente en unos minutos.',
            ], 503);
        }

        try {
            [$cliente, $usuario] = DB::transaction(function () use ($data, $photoPath): array {
                $cliente = Cliente::create([
                    'nombre' => trim($data['nombre']),
                    'telefono' => $data['telefono'],
                    'whatsapp' => $data['whatsapp'] ?? $data['telefono'],
                    'documento' => $data['ci'],
                    'ci_expedido' => $data['ci_expedido'] ?? null,
                    'foto_path' => $photoPath,
                    'perfil_completo_at' => now(),
                    'ciudad' => trim($data['ciudad']),
                    'direccion' => $data['direccion'] ?? null,
                    'estado' => 'nuevo',
                    'canal_origen' => $data['canal_origen'] ?? 'viti',
                ]);

                $usuario = Usuario::create([
                    'cliente_id' => $cliente->id,
                    'nombre' => trim($data['nombre']),
                    'usuario' => $data['usuario'],
                    'telefono' => $data['telefono'],
                    'password' => $data['password'],
                    'rol' => 'cliente',
                    'estado' => 'activo',
                ]);

                return [$cliente, $usuario];
            });
        } catch (Throwable $e) {
            try { Storage::disk('public')->delete($photoPath); } catch (Throwable) {}
            throw $e;
        }

        auth()->login($usuario);
        $request->session()->regenerate();
        Audit::log($request, 'cliente_registrado', $cliente, 'El cliente creó su acceso a VITI.');

        return response()->json([
            'message' => 'Tu cuenta fue creada correctamente.',
            'usuario' => $usuario->load('cliente'),
            'cliente' => $cliente,
        ], 201);
    }
}
