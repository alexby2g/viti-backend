<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'No tienes permiso para realizar esta acción.');
        return $next($request);
    }
}
