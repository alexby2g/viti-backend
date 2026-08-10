<?php

namespace App\Http\Controllers;

use App\Models\{Cliente,Cuestionario,Empresa,InvitacionCliente,SolicitudSistema,Usuario};
use App\Support\Code;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Throwable;

class OnboardingController extends Controller
{
    public function createInvitation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dias_vigencia' => ['nullable','integer','min:1','max:30'],
        ]);

        $days = (int) ($data['dias_vigencia'] ?? 7);
        $invitation = InvitacionCliente::create([
            'token' => Str::random(64),
            'creada_por' => $request->user()?->id,
            'estado' => 'pendiente',
            'expira_at' => now()->addDays($days),
        ]);

        return response()->json([
            'data' => [
                'token' => $invitation->token,
                'estado' => $invitation->estado,
                'expira_at' => $invitation->expira_at,
                'ruta' => '/registro-cliente/'.$invitation->token,
            ],
            'message' => 'Enlace de registro creado correctamente.',
        ], 201);
    }

    public function showInvitation(string $token): JsonResponse
    {
        $invitation = $this->resolveInvitation($token);

        return response()->json([
            'data' => [
                'estado' => $invitation->estado,
                'expira_at' => $invitation->expira_at,
            ],
        ]);
    }

    public function register(Request $request, string $token): JsonResponse
    {
        $invitation = $this->resolveInvitation($token);
        $request->merge([
            'usuario' => Str::lower(trim((string) $request->input('usuario'))),
            'ci' => preg_replace('/\D+/', '', (string) $request->input('ci')),
        ]);

        $data = $request->validate([
            'nombre' => ['required','string','min:3','max:180'],
            'usuario' => ['required','string','alpha_dash','min:4','max:40','not_regex:/^\d+$/','unique:usuarios,usuario'],
            'telefono' => ['required','regex:/^[0-9]{7,15}$/',Rule::unique('usuarios','telefono')],
            'whatsapp' => ['nullable','regex:/^[0-9]{7,15}$/'],
            'ci' => ['required','regex:/^[0-9]{5,15}$/',Rule::unique('usuarios','documento')],
            'ci_expedido' => ['nullable','string','max:20'],
            'ciudad' => ['required','string','max:100'],
            'direccion' => ['nullable','string','max:255'],
            'foto' => ['nullable','image','mimes:jpg,jpeg,png,webp','max:4096'],
            'password' => ['required','confirmed', Password::min(10)->letters()->numbers()],
            'empresa_nombre' => ['required','string','max:180'],
            'empresa_actividad' => ['nullable','string','max:200'],
            'empresa_telefono' => ['nullable','string','max:30'],
            'empresa_whatsapp' => ['nullable','string','max:30'],
            'empresa_ciudad' => ['nullable','string','max:100'],
            'empresa_direccion' => ['nullable','string','max:255'],
            'titulo_sistema' => ['required','string','max:200'],
            'resumen' => ['nullable','string','max:5000'],
        ], [
            'nombre.required' => 'Ingresa tu nombre completo.',
            'usuario.required' => 'Crea un nombre de usuario para ingresar a VITI.',
            'usuario.alpha_dash' => 'El usuario solo puede contener letras, números, guiones y guiones bajos.',
            'usuario.min' => 'El usuario debe tener al menos 4 caracteres.',
            'usuario.not_regex' => 'El usuario debe incluir al menos una letra.',
            'usuario.unique' => 'Ese nombre de usuario ya está registrado.',
            'telefono.required' => 'Ingresa tu número de teléfono.',
            'telefono.regex' => 'El teléfono debe contener entre 7 y 15 dígitos.',
            'telefono.unique' => 'Ese número de teléfono ya está registrado en VITI.',
            'whatsapp.regex' => 'El número de WhatsApp debe contener entre 7 y 15 dígitos.',
            'ci.required' => 'Ingresa tu número de cédula de identidad.',
            'ci.regex' => 'El CI debe contener entre 5 y 15 dígitos.',
            'ci.unique' => 'Ese número de cédula ya está registrado.',
            'ciudad.required' => 'Indica tu ciudad o localidad.',
            'password.required' => 'Crea una contraseña para tu cuenta VITI.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'empresa_nombre.required' => 'Ingresa el nombre de tu negocio, institución o proyecto.',
            'titulo_sistema.required' => 'Escribe brevemente qué sistema necesitas.',
            'foto.max' => 'La fotografía no puede superar 4 MB.',
        ]);

        $photoPath = null;
        if ($request->hasFile('foto')) {
            try {
                $photoPath = $request->file('foto')->store('clientes/fotos','public');
                if (!$photoPath || !Storage::disk('public')->exists($photoPath)) {
                    throw new \RuntimeException('El almacenamiento no confirmó la fotografía.');
                }
            } catch (Throwable $e) {
                report($e);
                return response()->json([
                    'message' => 'No pudimos guardar la fotografía. Puedes intentarlo nuevamente en unos minutos.',
                ], 503);
            }
        }

        try {
            $result = DB::transaction(function () use ($data, $photoPath, $invitation): array {
                $locked = InvitacionCliente::query()->lockForUpdate()->findOrFail($invitation->id);
                abort_unless($locked->estado === 'pendiente', 410, 'Este enlace ya fue utilizado.');
                abort_if($locked->expira_at && $locked->expira_at->isPast(), 410, 'Este enlace de registro ya venció.');

                $questionnaireId = Cuestionario::query()->where('activo', true)->value('id');
                abort_unless($questionnaireId, 422, 'VITI no tiene un cuestionario activo en este momento.');

                $document = trim($data['ci']);
                $cliente = Cliente::query()->where('telefono', $data['telefono'])->lockForUpdate()->first();
                $documentOwner = Cliente::query()->where('documento', $document)->lockForUpdate()->first();

                abort_if(
                    $documentOwner && (!$cliente || (int) $documentOwner->id !== (int) $cliente->id),
                    422,
                    'Ese número de cédula ya está registrado con otro cliente.'
                );

                if ($cliente) {
                    abort_if(
                        filled($cliente->documento) && $cliente->documento !== $document,
                        422,
                        'La cédula no coincide con la ficha existente para ese teléfono.'
                    );
                    abort_if(
                        Usuario::query()->where('cliente_id', $cliente->id)->exists(),
                        422,
                        'Este cliente ya tiene una cuenta. Ingresa a VITI con su usuario y contraseña.'
                    );

                    $oldPhotoPath = $cliente->foto_path;
                    $cliente->update([
                        'nombre' => trim($data['nombre']),
                        'whatsapp' => $data['whatsapp'] ?? $data['telefono'],
                        'documento' => $document,
                        'ci_expedido' => $data['ci_expedido'] ?? $cliente->ci_expedido,
                        'ciudad' => trim($data['ciudad']),
                        'direccion' => $data['direccion'] ?? $cliente->direccion,
                        'foto_path' => $photoPath ?: $cliente->foto_path,
                        'perfil_completo_at' => $photoPath ? now() : $cliente->perfil_completo_at,
                        'estado' => 'formulario_en_proceso',
                    ]);
                } else {
                    $oldPhotoPath = null;
                    $cliente = Cliente::create([
                        'nombre' => trim($data['nombre']),
                        'telefono' => $data['telefono'],
                        'whatsapp' => $data['whatsapp'] ?? $data['telefono'],
                        'documento' => $document,
                        'ci_expedido' => $data['ci_expedido'] ?? null,
                        'ciudad' => trim($data['ciudad']),
                        'direccion' => $data['direccion'] ?? null,
                        'foto_path' => $photoPath,
                        'perfil_completo_at' => $photoPath ? now() : null,
                        'estado' => 'formulario_en_proceso',
                        'canal_origen' => 'viti',
                    ]);
                }

                $usuario = Usuario::create([
                    'cliente_id' => $cliente->id,
                    'nombre' => trim($data['nombre']),
                    'usuario' => $data['usuario'],
                    'documento' => $document,
                    'telefono' => $data['telefono'],
                    'password' => $data['password'],
                    'rol' => 'cliente',
                    'estado' => 'activo',
                ]);

                $companyName = trim($data['empresa_nombre']);
                $empresa = Empresa::query()
                    ->where('cliente_id', $cliente->id)
                    ->whereRaw('LOWER(nombre_comercial) = ?', [Str::lower($companyName)])
                    ->lockForUpdate()
                    ->first();

                $companyData = [
                    'actividad' => $data['empresa_actividad'] ?? null,
                    'telefono' => $data['empresa_telefono'] ?? $data['telefono'],
                    'whatsapp' => $data['empresa_whatsapp'] ?? ($data['whatsapp'] ?? $data['telefono']),
                    'ciudad' => $data['empresa_ciudad'] ?? $data['ciudad'],
                    'direccion' => $data['empresa_direccion'] ?? $data['direccion'] ?? null,
                ];

                if ($empresa) {
                    $empresa->update(array_filter($companyData, fn ($value) => filled($value)));
                } else {
                    $empresa = Empresa::create(array_merge($companyData, [
                        'cliente_id' => $cliente->id,
                        'codigo' => Code::next('empresas','EMP'),
                        'nombre_comercial' => $companyName,
                        'estado' => 'pendiente_revision',
                    ]));
                }

                $empresa->usuarios()->syncWithoutDetaching([$usuario->id => [
                    'rol_negocio' => 'propietario',
                    'activo' => true,
                ]]);

                $requestTitle = trim($data['titulo_sistema']);
                $solicitud = SolicitudSistema::query()
                    ->where('cliente_id', $cliente->id)
                    ->where('empresa_id', $empresa->id)
                    ->whereRaw('LOWER(titulo) = ?', [Str::lower($requestTitle)])
                    ->whereIn('estado', ['borrador','en_revision'])
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if ($solicitud) {
                    $solicitud->update([
                        'public_token' => $solicitud->public_token ?: Str::random(48),
                        'publico_habilitado' => true,
                        'resumen' => $solicitud->resumen ?: ($data['resumen'] ?? null),
                        'acuerdo_comercial_requerido' => true,
                    ]);
                } else {
                    $solicitud = SolicitudSistema::create([
                        'empresa_id' => $empresa->id,
                        'cliente_id' => $cliente->id,
                        'cuestionario_id' => $questionnaireId,
                        'codigo' => Code::next('solicitudes_sistema','SOL'),
                        'public_token' => Str::random(48),
                        'publico_habilitado' => true,
                        'titulo' => $requestTitle,
                        'resumen' => $data['resumen'] ?? null,
                        'estado' => 'borrador',
                        'prioridad' => 'normal',
                        'acuerdo_comercial_requerido' => true,
                    ]);
                }

                $locked->update([
                    'estado' => 'usada',
                    'cliente_id' => $cliente->id,
                    'solicitud_id' => $solicitud->id,
                    'usada_at' => now(),
                ]);

                return [$cliente, $empresa, $solicitud, $oldPhotoPath];
            });
        } catch (Throwable $e) {
            if ($photoPath) {
                try { Storage::disk('public')->delete($photoPath); } catch (Throwable) {}
            }
            throw $e;
        }

        [$cliente, $empresa, $solicitud, $oldPhotoPath] = $result;

        if ($photoPath && $oldPhotoPath && $oldPhotoPath !== $photoPath) {
            try { Storage::disk('public')->delete($oldPhotoPath); } catch (Throwable) {}
        }

        return response()->json([
            'message' => 'Tu registro fue creado. Ahora completa el cuestionario de tu sistema.',
            'data' => [
                'cliente' => $cliente,
                'empresa' => $empresa,
                'solicitud_codigo' => $solicitud->codigo,
                'solicitud_token' => $solicitud->public_token,
                'ruta_cuestionario' => '/solicitar/'.$solicitud->public_token,
            ],
        ], 201);
    }

    private function resolveInvitation(string $token): InvitacionCliente
    {
        $invitation = InvitacionCliente::query()->where('token', $token)->firstOrFail();
        abort_unless($invitation->estado === 'pendiente', 410, 'Este enlace ya fue utilizado o deshabilitado.');
        abort_if($invitation->expira_at && $invitation->expira_at->isPast(), 410, 'Este enlace de registro ya venció.');
        return $invitation;
    }
}
