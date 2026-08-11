<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureInternalSupport
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->rol === 'soporte', 403, 'Esta función requiere una cuenta de soporte interno.');
        return $next($request);
    }
}
