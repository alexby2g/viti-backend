<?php

namespace App\Http\Middleware;

use App\Services\AuthSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SessionLifecycle
{
    public function __construct(private readonly AuthSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->skip($request)) return $next($request);

        $usuario = $request->user('sanctum');
        if (!$usuario) return $next($request);

        try {
            $this->sessions->enforce($request,$usuario);
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 401 && !$request->bearerToken() && $request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            throw $e;
        }

        return $next($request);
    }

    private function skip(Request $request): bool
    {
        return $request->is('api/health')
            || $request->is('api/v1/setup*')
            || $request->is('api/v1/auth/login')
            || $request->is('api/v1/auth/logout')
            || $request->is('api/v1/auth/cliente/registro')
            || $request->is('api/v1/auth/electrofrio/login')
            || $request->is('api/v1/mobile/login')
            || $request->is('api/v1/mobile/version')
            || $request->is('api/v1/publico/*');
    }
}
