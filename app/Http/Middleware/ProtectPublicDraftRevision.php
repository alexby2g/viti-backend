<?php

namespace App\Http\Middleware;

use App\Models\SolicitudSistema;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ProtectPublicDraftRevision
{
    public function handle(Request $request, Closure $next): Response
    {
        return DB::transaction(function () use ($request, $next): Response {
            $token = (string) $request->route('token');
            $solicitud = SolicitudSistema::query()
                ->where('public_token', $token)
                ->where('publico_habilitado', true)
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($solicitud->estado, ['borrador','rechazada'], true)) {
                return response()->json([
                    'message'=>'Esta solicitud ya fue enviada o cerrada y no admite más cambios desde este enlace.',
                    'estado'=>$solicitud->estado,
                    'current_revision'=>(int) $solicitud->draft_revision,
                ], 409);
            }

            if ($request->has('base_revision')) {
                $baseRevision = filter_var($request->input('base_revision'), FILTER_VALIDATE_INT);
                if ($baseRevision === false || $baseRevision < 0) {
                    return response()->json([
                        'message'=>'La versión del borrador no es válida. Recarga la solicitud e inténtalo nuevamente.',
                    ], 422);
                }

                if ((int) $baseRevision !== (int) $solicitud->draft_revision) {
                    return response()->json([
                        'message'=>'Hay cambios más recientes guardados desde otro dispositivo o pestaña.',
                        'estado'=>$solicitud->estado,
                        'current_revision'=>(int) $solicitud->draft_revision,
                        'draft_saved_at'=>$solicitud->draft_saved_at?->toIso8601String(),
                    ], 409);
                }
            }

            $response = $next($request);

            if ($response->isSuccessful()) {
                $solicitud->refresh();
                $solicitud->forceFill([
                    'draft_revision'=>(int) $solicitud->draft_revision + 1,
                    'draft_saved_at'=>now(),
                ])->save();

                $response->headers->set('X-VITI-Draft-Revision', (string) $solicitud->draft_revision);
                $response->headers->set('X-VITI-Draft-Saved-At', $solicitud->draft_saved_at?->toIso8601String() ?? '');

                if ($response instanceof JsonResponse) {
                    $payload = $response->getData(true);
                    $payload['draft'] = [
                        'revision'=>(int) $solicitud->draft_revision,
                        'saved_at'=>$solicitud->draft_saved_at?->toIso8601String(),
                    ];
                    $response->setData($payload);
                }
            }

            return $response;
        }, 3);
    }
}
