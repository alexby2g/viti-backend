<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseActiveCompanyForNewRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->isMethod('post')
            && $request->is('api/v1/mi/solicitud')
            && !$request->filled('empresa_id')
            && filled($request->header('X-VITI-Empresa'))
        ) {
            $request->merge(['empresa_id' => (int) $request->header('X-VITI-Empresa')]);
        }

        return $next($request);
    }
}
