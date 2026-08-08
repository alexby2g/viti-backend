<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureClient
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $hasBusiness = $user?->negocios()->wherePivot('activo',true)->exists() ?? false;
        abort_unless($user?->rol === 'cliente' && ($user?->cliente_id || $hasBusiness), 403, 'Este acceso es exclusivo para usuarios de negocios VITI.');
        return $next($request);
    }
}
