<?php

namespace App\Http\Controllers;

use App\Models\{Cliente, Empresa, Usuario};
use App\Support\{Audit, Code};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;

class ClientAuthController extends Controller
{
    /**
     * Registro público simple de una nueva cuenta VITI.
     * No obliga a elegir plan ni a describir un sistema antes de crear la cuenta.
     */
    public function createAccount(Request $request): JsonResponse
    {
        $request->merge([
            'correo' => Str::lower(trim((string) $request->input('correo'))),
            'celular' => $this->digits($request->input('celular')),
            'whatsapp' => $this->digits($request->input('whatsapp')),
            'whatsapp_business' => $this->digits($request->input('whatsapp_business')),
        ]);

        $data = $request->validate([
            'nombre' => ['required','string','min:3','max:180'],
            // La cuenta y el prospecto son conceptos distintos. El correo puede existir en
            // clientes/solicitudes, pero no puede pertenecer a otro usuario de acceso.
            'correo' => ['required','email','max:160',Rule::unique('usuarios','correo')],
            'celular' => ['required','regex:/^[0-9]{7,15}$/',Rule::unique('usuarios','telefono')],
            'whatsapp' => ['nullable','regex:/^[0-9]{7,15}$/'],
            'whatsapp_business' => ['nullable','regex:/^[0-9]{7,15}$/'],
            'password' => ['required','confirmed',Password::min(8)->letters()->numbers()],
        ], [
            'nombre.required' => 'Escribe tu nombre completo.',
            'correo.required' => 'Escribe tu correo electrónico.',
            'correo.email' => 'Escribe un correo válido.',
            'correo.unique' => 'Ya existe una cuenta VITI con ese correo. Inicia sesión para continuar.',
            'celular.required' => 'Escribe tu número de celular.',
            'celular.regex' => 'El celular debe tener entre 7 y 15 dígitos.',
            'celular.unique' => 'Ya existe una cuenta VITI con ese celular. Inicia sesión para continuar.',
            'whatsapp.regex' => 'El WhatsApp debe tener entre 7 y 15 dígitos.',
            'whatsapp_business.regex' => 'El WhatsApp del negocio debe tener entre 7 y 15 dígitos.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        [$firstName, $lastName] = $this->splitName($data['nombre']);
        $username = $this->uniqueUsername($data['correo']);

        [$usuario, $cliente, $empresa, $linkedRequests] = DB::transaction(function () use ($data, $firstName, $lastName, $username): array {
            $byEmail = Cliente::query()->whereRaw('LOWER(correo) = ?', [$data['correo']])->first();
            $byPhone = Cliente::query()->where('telefono', $data['celular'])->first();

            if ($byEmail && $byPhone && (int) $byEmail->id !== (int) $byPhone->id) {
                throw ValidationException::withMessages([
                    'correo' => 'El correo y el celular están asociados a contactos distintos. Contacta a soporte VITI para unirlos.',
                ]);
            }

            $cliente = $byEmail ?: $byPhone;
            if ($cliente && $cliente->usuario()->exists()) {
                throw ValidationException::withMessages([
                    'correo' => 'Ya tienes una cuenta VITI. Inicia sesión para continuar.',
                ]);
            }

            if ($cliente) {
                $cliente->update([
                    'nombre' => trim($data['nombre']),
                    'correo' => $data['correo'],
                    'telefono' => $data['celular'],
                    'whatsapp' => $data['whatsapp'] ?: $cliente->whatsapp ?: $data['celular'],
                    'whatsapp_business' => $data['whatsapp_business'] ?: $cliente->whatsapp_business,
                    'canal_origen' => $cliente->canal_origen ?: 'registro_web',
                ]);
            } else {
                $cliente = Cliente::create([
                    'nombre' => trim($data['nombre']),
                    'telefono' => $data['celular'],
                    'whatsapp' => $data['whatsapp'] ?: $data['celular'],
                    'whatsapp_business' => $data['whatsapp_business'] ?: null,
                    'correo' => $data['correo'],
                    'estado' => 'prospecto',
                    'canal_origen' => 'registro_web',
                ]);
            }

            $usuario = Usuario::create([
                'cliente_id' => $cliente->id,
                'nombre' => $firstName,
                'apellido' => $lastName,
                'usuario' => $username,
                'telefono' => $data['celular'],
                'correo' => $data['correo'],
                'password' => $data['password'],
                'rol' => 'cliente',
                'estado' => 'activo',
            ]);

            // Si la persona primero envió una solicitud, reutilizamos exactamente ese
            // cliente y sus empresas. No se crea un negocio ficticio "Mi negocio".
            $empresas = Empresa::query()->where('cliente_id', $cliente->id)->orderBy('id')->get();
            foreach ($empresas as $business) {
                $business->usuarios()->syncWithoutDetaching([
                    $usuario->id => ['rol_negocio'=>'propietario','activo'=>true],
                ]);
            }

            return [
                $usuario,
                $cliente,
                $empresas->first(),
                $cliente->solicitudes()->count(),
            ];
        });

        if ($request->hasSession()) {
            auth()->login($usuario, false);
            $request->session()->regenerate();
        }
        $usuario->forceFill(['ultimo_acceso'=>now()])->save();

        Audit::log($request, 'cuenta_cliente_registrada', $usuario, 'El cliente creó o vinculó su cuenta VITI.', [
            'solicitudes_vinculadas' => $linkedRequests,
        ]);

        return response()->json([
            'message' => $linkedRequests > 0
                ? 'Tu cuenta VITI fue creada y vinculamos tus solicitudes anteriores automáticamente.'
                : 'Tu cuenta VITI fue creada. Puedes solicitar un sistema cuando quieras.',
            'usuario' => $usuario->fresh()->loadMissing('cliente'),
            'data' => [
                'cliente' => $cliente->fresh(),
                'empresa' => $empresa?->fresh(),
                'solicitudes_vinculadas' => $linkedRequests,
                'sin_plan' => true,
            ],
        ], 201);
    }

    /**
     * Completa el alta de una cuenta creada mediante Google.
     * También queda sin plan y sin obligar al usuario a describir un sistema.
     */
    public function register(Request $request): JsonResponse
    {
        $request->merge([
            'celular' => $this->digits($request->input('celular')),
            'whatsapp' => $this->digits($request->input('whatsapp')),
            'whatsapp_business' => $this->digits($request->input('whatsapp_business')),
        ]);

        $data = $request->validate([
            'token' => ['required','string','size:64'],
            'nombre' => ['required','string','max:180'],
            'celular' => ['required','regex:/^[0-9]{7,15}$/',Rule::unique('usuarios','telefono')],
            'whatsapp' => ['nullable','regex:/^[0-9]{7,15}$/'],
            'whatsapp_business' => ['nullable','regex:/^[0-9]{7,15}$/'],
        ]);

        $userId = Cache::pull('viti:google:onboarding:'.$data['token']);
        abort_unless($userId, 410, 'El enlace de onboarding expiró. Inicia nuevamente con Google.');

        $usuario = Usuario::query()->where('id',$userId)->where('rol','cliente')->where('estado','activo')->firstOrFail();
        abort_if($usuario->cliente_id, 409, 'La cuenta ya tiene un perfil de cliente configurado.');

        [$cliente, $empresa, $linkedRequests] = DB::transaction(function () use ($data, $usuario): array {
            $email = Str::lower(trim((string) $usuario->correo));
            $byEmail = $email !== '' ? Cliente::query()->whereRaw('LOWER(correo) = ?', [$email])->first() : null;
            $byPhone = Cliente::query()->where('telefono', $data['celular'])->first();
            if ($byEmail && $byPhone && (int) $byEmail->id !== (int) $byPhone->id) {
                throw ValidationException::withMessages(['celular'=>'El correo y el celular están asociados a contactos distintos. Contacta a soporte VITI.']);
            }

            $cliente = $byEmail ?: $byPhone;
            if ($cliente && $cliente->usuario()->exists()) {
                throw ValidationException::withMessages(['celular'=>'Ese contacto ya tiene una cuenta VITI.']);
            }

            if ($cliente) {
                $cliente->update([
                    'nombre' => trim($data['nombre']),
                    'telefono' => $data['celular'],
                    'whatsapp' => $data['whatsapp'] ?: $cliente->whatsapp ?: $data['celular'],
                    'whatsapp_business' => $data['whatsapp_business'] ?: $cliente->whatsapp_business,
                    'correo' => $email ?: $cliente->correo,
                ]);
            } else {
                $cliente = Cliente::create([
                    'nombre' => trim($data['nombre']),
                    'telefono' => $data['celular'],
                    'whatsapp' => $data['whatsapp'] ?: $data['celular'],
                    'whatsapp_business' => $data['whatsapp_business'] ?: null,
                    'correo' => $email ?: null,
                    'estado' => 'prospecto',
                    'canal_origen' => 'google',
                ]);
            }

            $usuario->update([
                'cliente_id' => $cliente->id,
                'nombre' => $this->splitName($data['nombre'])[0],
                'apellido' => $this->splitName($data['nombre'])[1],
                'telefono' => $data['celular'],
            ]);

            $empresas = Empresa::query()->where('cliente_id', $cliente->id)->orderBy('id')->get();
            foreach ($empresas as $business) {
                $business->usuarios()->syncWithoutDetaching([
                    $usuario->id => ['rol_negocio'=>'propietario','activo'=>true],
                ]);
            }

            return [$cliente, $empresas->first(), $cliente->solicitudes()->count()];
        });

        Audit::log($request, 'cliente_google_onboarding_completado', $cliente, 'El cliente completó su registro con Google.', [
            'solicitudes_vinculadas' => $linkedRequests,
        ]);

        return response()->json([
            'message' => $linkedRequests > 0
                ? 'Tu cuenta está lista y vinculamos tus solicitudes anteriores.'
                : 'Tu cuenta VITI está lista. Puedes explorar y solicitar un sistema cuando quieras.',
            'usuario' => $usuario->fresh()->loadMissing('cliente'),
            'data' => [
                'cliente' => $cliente->fresh()->load('empresas'),
                'empresa' => $empresa,
                'solicitudes_vinculadas' => $linkedRequests,
                'sin_plan' => true,
            ],
        ], 201);
    }

    private function digits(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? ''));
        return $digits !== '' ? $digits : null;
    }

    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2);
        return [
            trim((string) ($parts[0] ?? 'Cliente')) ?: 'Cliente',
            ($parts[1] ?? null) ? trim((string) $parts[1]) : null,
        ];
    }

    private function uniqueUsername(string $email): string
    {
        $base = Str::slug(Str::before($email, '@'), '_') ?: 'cliente';
        $base = Str::limit($base, 68, '');
        $username = $base;
        $suffix = 1;
        while (Usuario::query()->where('usuario', $username)->exists()) {
            $username = $base.'_'.(++$suffix);
        }
        return $username;
    }
}
