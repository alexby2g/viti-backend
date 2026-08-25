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

        // PUT is still part of the current public draft editor. Only reject
        // the old token endpoint when the token does not resolve to an active
        // editable draft; real draft requests continue through the controller.
        if ($request->isMethod('PUT') && $request->is('api/v1/publico/solicitudes/*')) {
            $token = (string) $request->route('token');
            $draftExists = $token !== '' && SolicitudSistema::query()
                ->where('public_token', $token)
                ->where('publico_habilitado', true)
                ->whereIn('estado', ['borrador', 'en_revision'])
                ->exists();

            if (!$draftExists) {
                return response()->json([
                    'message' => 'Este endpoint público legado fue retirado. Utiliza el formulario oficial de solicitud VITI.',
                ], 410);
            }
        }

        return $next($request);
    }
}
