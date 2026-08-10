<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivateVitiCommunication
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || $user->rol !== 'administrador') return $next($request);

        $path = $request->path();
        $privateViti =
            str_starts_with($path, 'api/v1/buzon') ||
            str_starts_with($path, 'api/v1/atencion/') ||
            ($path === 'api/v1/llamadas' || (str_starts_with($path, 'api/v1/llamadas/') && $path !== 'api/v1/llamadas/entrante'));

        abort_if($privateViti, 403, 'La comunicación privada VITI está reservada al superadministrador.');
        return $next($request);
    }
}
