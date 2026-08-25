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

        // The old plural public endpoint accepted a very small legacy payload.
        // Keep that exact signature retired while allowing the current plural
        // draft flow, which requires the complete requester and project data.
        if ($request->isMethod('POST') && $request->is('api/v1/publico/solicitudes')
            && (!$request->filled('ciudad') || !$request->filled('titulo_sistema'))) {
            return response()->json([
                'message' => 'Este formulario público legado fue retirado. Utiliza el formulario oficial de solicitud VITI.',
            ], 410);
        }

        // The token editor is part of the current public flow. Only reject a
        // token that does not exist at all; state, expiry and stale-write rules
        // are enforced by the controller and ProtectPublicDraftRevision.
        if ($request->isMethod('PUT') && $request->is('api/v1/publico/solicitudes/*')) {
            $token = trim((string) $request->route('token'));
            if ($token === '') {
                $token = trim((string) basename(parse_url($request->path(), PHP_URL_PATH) ?: ''));
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
