<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureClient
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->rol === 'cliente' && $request->user()?->cliente_id, 403, 'Este acceso es exclusivo para clientes de VITI.');
        return $next($request);
    }
}
