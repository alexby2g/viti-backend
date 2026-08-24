<?php

namespace App\Http\Middleware;

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

        if ($request->is('api/v1/publico/solicitudes') || $request->is('api/v1/publico/solicitudes/*')) {
            return response()->json([
                'message' => 'Este formulario público fue retirado. Utiliza el proceso oficial de solicitud VITI.',
            ], 410);
        }

        return $next($request);
    }
}
