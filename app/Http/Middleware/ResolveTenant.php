<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;

class ResolveTenant
{
    public function __construct(private TenantContext $tenants) {}

    public function handle(Request $request, Closure $next)
    {
        $empresa = $this->tenants->resolve($request);
        $request->attributes->set('viti_empresa',$empresa);
        return $next($request);
    }
}
