<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProjectRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $project = $request->route('proyecto');

        if (!$user || !$project) {
            abort(403, 'No tienes permiso para acceder a este proyecto.');
        }

        $globalRole = (string) $user->rol;
        if (in_array($globalRole, ['superadmin', 'administrador'], true)) {
            return $next($request);
        }

        $member = $project->miembros()
            ->where('usuario_id', $user->id)
            ->where('activo', true)
            ->first();

        if (!$member) {
            abort(403, 'No estás asignado a este proyecto.');
        }

        $allowed = array_map('strtolower', array_filter($roles));
        $memberRole = strtolower((string) $member->rol);

        if ($allowed !== [] && !in_array($memberRole, $allowed, true)) {
            abort(403, 'Tu rol en este proyecto no permite realizar esta acción.');
        }

        $request->attributes->set('project_member', $member);
        return $next($request);
    }
}
