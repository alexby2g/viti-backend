<?php

namespace App\Http\Middleware;

use App\Models\SolicitudSistema;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectLegacyVitiFlows
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->is('api/v1/invitaciones-clientes')) {
            return response()->json([
                'message' => 'Este flujo de invitación fue retirado. Las invitaciones solo se generan desde una solicitud VITI aprobada.',
            ], 410);
        }

        if ($request->isMethod('POST') && $request->is('api/v1/publico/solicitudes')
            && (!$request->filled('ciudad') || !$request->filled('titulo_sistema'))) {
            return response()->json([
                'message' => 'Este formulario público legado fue retirado. Utiliza el formulario oficial de solicitud VITI.',
            ], 410);
        }

        $path = trim($request->path(), '/');
        $isPublicRequestRead = $request->isMethod('GET')
            && str_starts_with($path, 'api/v1/publico/solicitudes/');
        $isPublicRequestPut = $request->isMethod('PUT')
            && str_starts_with($path, 'api/v1/publico/solicitudes/');
        $isPublicRequestSubmit = $request->isMethod('POST')
            && str_ends_with($path, '/enviar')
            && str_starts_with($path, 'api/v1/publico/solicitudes/');

        if ($isPublicRequestRead || $isPublicRequestPut || $isPublicRequestSubmit) {
            $segments = array_values(array_filter(explode('/', $path), static fn ($segment) => $segment !== ''));
            $token = trim((string) $request->route('token'));

            if ($token === '') {
                $token = $isPublicRequestSubmit
                    ? (string) ($segments[count($segments) - 2] ?? '')
                    : (string) ($segments[count($segments) - 1] ?? '');
            }

            $tokenExists = $token !== '' && SolicitudSistema::query()
                ->where('public_token', $token)
                ->exists();

            if (!$tokenExists) {
                return response()->json([
                    'message' => 'Este enlace público de solicitud ya no está disponible. Inicia una nueva solicitud VITI.',
                ], 410);
            }
        }

        return $next($request);
    }
}
