<?php

namespace App\Http\Controllers;

use App\Models\{Cliente, Empresa, Usuario};
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $clientId = (string) config('services.google.client_id');
        $redirect = (string) config('services.google.redirect');

        if ($clientId === '' || $redirect === '') {
            return $this->frontendRedirect('login', ['google' => 'not_configured']);
        }

        $state = Str::random(64);
        Cache::put('viti:google:state:'.$state, true, now()->addMinutes(10));

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state');
        if ($state === '' || !Cache::pull('viti:google:state:'.$state)) {
            return $this->frontendRedirect('login', ['google' => 'state_mismatch']);
        }

        if ($request->filled('error')) {
            return $this->frontendRedirect('login', ['google' => 'cancelled']);
        }

        $code = (string) $request->query('code');
        if ($code === '') {
            return $this->frontendRedirect('login', ['google' => 'missing_code']);
        }

        try {
            $tokenResponse = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => config('services.google.redirect'),
                'grant_type' => 'authorization_code',
            ]);

            if (!$tokenResponse->successful()) {
                Log::warning('VITI Google OAuth token exchange failed.', ['status' => $tokenResponse->status()]);
                return $this->frontendRedirect('login', ['google' => 'token_exchange_failed']);
            }

            $accessToken = (string) $tokenResponse->json('access_token');
            if ($accessToken === '') {
                return $this->frontendRedirect('login', ['google' => 'missing_access_token']);
            }

            $profileResponse = Http::withToken($accessToken)->acceptJson()->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo');
            if (!$profileResponse->successful()) {
                Log::warning('VITI Google OAuth userinfo failed.', ['status' => $profileResponse->status()]);
                return $this->frontendRedirect('login', ['google' => 'profile_failed']);
            }

            $profile = $profileResponse->json();
            $sub = trim((string) ($profile['sub'] ?? ''));
            $email = strtolower(trim((string) ($profile['email'] ?? '')));
            $verified = filter_var($profile['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

            if ($sub === '' || $email === '' || !$verified) {
                return $this->frontendRedirect('login', ['google' => 'email_not_verified']);
            }

            $usuario = Usuario::query()->where('google_sub', $sub)->first()
                ?? Usuario::query()->whereRaw('LOWER(correo) = ?', [$email])->first();

            $created = false;
            if ($usuario && $usuario->estado !== 'activo') {
                return $this->frontendRedirect('login', ['google' => 'needs_access']);
            }

            if (!$usuario) {
                $displayName = trim((string) ($profile['name'] ?? Str::before($email, '@')));
                $parts = preg_split('/\s+/', $displayName, 2);
                $nombre = trim((string) ($parts[0] ?? 'Cliente')) ?: 'Cliente';
                $apellido = trim((string) ($parts[1] ?? '')) ?: null;
                $baseUsername = Str::slug(Str::before($email, '@'), '_') ?: 'cliente';
                $username = $baseUsername;
                $suffix = 1;
                while (Usuario::query()->where('usuario', $username)->exists()) {
                    $username = $baseUsername.'_'.(++$suffix);
                }

                $usuario = DB::transaction(function () use ($email, $sub, $nombre, $apellido, $username): Usuario {
                    return Usuario::create([
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'usuario' => $username,
                        'correo' => $email,
                        'google_sub' => $sub,
                        'password' => Hash::make(Str::random(64)),
                        'rol' => 'cliente',
                        'estado' => 'activo',
                    ]);
                });
                $created = true;
                Audit::log($request, 'cuenta_cliente_google_creada', $usuario, 'Se creó una cuenta de cliente desde Google; pendiente de completar el onboarding.');
            } elseif (!$usuario->google_sub) {
                $usuario->forceFill(['google_sub' => $sub])->save();
            }

            auth()->login($usuario, false);
            if ($request->hasSession()) $request->session()->regenerate();
            $usuario->forceFill(['ultimo_acceso' => now()])->save();
            Audit::log($request, 'inicio_sesion_google', $usuario, $created ? 'Cuenta de cliente creada y sesión iniciada mediante Google.' : 'Inicio de sesión correcto mediante Google.');

            if ($created && !$usuario->cliente_id) {
                $token = Str::random(64);
                Cache::put('viti:google:onboarding:'.$token, $usuario->id, now()->addMinutes(20));
                return $this->frontendRedirect('onboarding/cliente', ['google' => 'created', 'token' => $token]);
            }

            $target = $usuario->rol === 'cliente' ? 'mi-cuenta' : 'login';
            return $this->frontendRedirect($target, ['google' => 'success']);
        } catch (\Throwable $e) {
            report($e);
            return $this->frontendRedirect('login', ['google' => 'unexpected_error']);
        }
    }

    private function frontendRedirect(string $path, array $query = []): RedirectResponse
    {
        $frontend = rtrim((string) config('services.google.frontend_url'), '/');
        $url = $frontend !== '' ? $frontend.'/'.$path : '/'.$path;
        return redirect()->to($url.($query ? '?'.http_build_query($query) : ''));
    }
}
