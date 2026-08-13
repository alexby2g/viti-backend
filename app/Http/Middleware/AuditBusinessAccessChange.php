<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditBusinessAccessChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() >= 400) return $response;

        $usuario = $request->route('usuario');
        if (!$usuario instanceof Usuario) return $response;

        $empresaId = (int)$request->attributes->get('viti_empresa_id', 0);
        $membership = $empresaId
            ? $usuario->negocios()->where('empresas.id', $empresaId)->first()
            : null;

        $isDelete = $request->isMethod('delete');
        $action = $isDelete ? 'usuario_negocio_desactivado' : 'usuario_negocio_actualizado';
        $description = $isDelete
            ? 'Se desactivó el acceso de '.$usuario->usuario.' al negocio.'
            : 'Se actualizó el acceso de '.$usuario->usuario.' al negocio.';

        Audit::log($request, $action, $usuario, $description, [
            'usuario_id' => $usuario->id,
            'rol_negocio' => $membership?->pivot?->rol_negocio ?? $request->input('rol_negocio'),
            'activo' => $membership ? (bool)$membership->pivot?->activo : (bool)$request->input('activo', !$isDelete),
            'password_actualizada' => !$isDelete && filled($request->input('password')),
        ]);

        return $response;
    }
}
