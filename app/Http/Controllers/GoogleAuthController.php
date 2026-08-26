<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

            if (!$usuario || $usuario->estado !== 'activo') {
                return $this->frontendRedirect('login', ['google' => 'needs_access']);
            }

            if (!$usuario->google_sub) {
                $usuario->forceFill(['google_sub' => $sub])->save();
            }

            auth()->login($usuario, false);
            $request->session()->regenerate();
            $usuario->forceFill(['ultimo_acceso' => now()])->save();
            Audit::log($request, 'inicio_sesion_google', $usuario, 'Inicio de sesión correcto mediante Google.');

            return $this->frontendRedirect('login', ['google' => 'success']);
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
