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
        $isPublicDraftPut = $request->isMethod('PUT')
            && str_starts_with($path, 'api/v1/publico/solicitudes/');

        if ($isPublicDraftPut) {
            $token = trim((string) $request->route('token'));
            if ($token === '') {
                $token = trim((string) basename($path));
            }

            $tokenExists = $token !== '' && SolicitudSistema::query()
                ->where('public_token', $token)
                ->exists();

            if (!$tokenExists) {
                return response()->json([
                    'message' => 'Este endpoint público legado fue retirado. Utiliza el formulario oficial de solicitud VITI.',
                ], 410);
            }
        }

        return $next($request);
    }
}
