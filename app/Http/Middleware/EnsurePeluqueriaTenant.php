<?php

namespace App\Http\Middleware;

use App\Models\Aplicacion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePeluqueriaTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->is('api/v1/apps/peluqueria/*') || $request->is('api/v1/apps/peluqueria/empresas')) {
            return $next($request);
        }

        $empresaId = $request->input('empresa_id') ?? $request->query('empresa_id');
        if (!$empresaId) return $next($request);

        $allowed = Aplicacion::query()
            ->where('empresa_id', (int) $empresaId)
            ->whereNotIn('estado', ['retirado'])
            ->whereHas('catalogo', fn ($q) => $q->where('clave', 'peluqueria')->where('activo', true))
            ->exists();

        abort_unless($allowed, 422, 'La empresa seleccionada no tiene Peluquería VITI habilitada.');

        return $next($request);
    }
}
