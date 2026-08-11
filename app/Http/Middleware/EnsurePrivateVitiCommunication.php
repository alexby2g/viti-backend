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
        if (!$user) return $next($request);

        $path = $request->path();

        if ($user->rol === 'administrador') {
            $privateViti =
                str_starts_with($path, 'api/v1/buzon') ||
                str_starts_with($path, 'api/v1/atencion/') ||
                ($path === 'api/v1/llamadas' || (str_starts_with($path, 'api/v1/llamadas/') && $path !== 'api/v1/llamadas/entrante'));
            abort_if($privateViti, 403, 'La comunicación privada VITI está reservada al superadministrador.');
        }

        if ($user->rol === 'soporte') {
            $globalCommunication =
                str_starts_with($path, 'api/v1/buzon') ||
                str_starts_with($path, 'api/v1/atencion/') ||
                str_starts_with($path, 'api/v1/notificaciones/buzon') ||
                str_starts_with($path, 'api/v1/llamadas');
            abort_if($globalCommunication, 403, 'Soporte interno solo puede acceder a comunicaciones asignadas desde su panel.');
        }

        return $next($request);
    }
}
