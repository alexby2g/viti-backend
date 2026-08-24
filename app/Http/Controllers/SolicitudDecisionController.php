<?php

namespace App\Http\Controllers;

use App\Models\{Conversacion,Mensaje,SolicitudSistema};
use App\Services\{AccessInvitationService,WorkflowStateService};
use App\Support\{Audit,FirebasePush};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SolicitudDecisionController extends Controller
{
    public function approve(Request $request, SolicitudSistema $solicitud, WorkflowStateService $workflow): JsonResponse
    {
        $solicitud->loadMissing(['cliente.usuario','empresa','planViti']);

        abort_unless($solicitud->estado === 'en_revision', 422, 'Solo se pueden aprobar solicitudes que están en revisión.');
        $workflow->assertSolicitudTransition($solicitud->estado, 'aprobada');
        abort_unless($solicitud->empresa_id && $solicitud->cliente_id, 422, 'La solicitud debe tener responsable y empresa antes de aprobarse.');
        abort_unless($solicitud->plan_viti_id && $solicitud->planViti, 422, 'La solicitud debe tener un plan VITI seleccionado.');
        abort_unless($solicitud->declaracion_aceptada, 422, 'La solicitud todavía no tiene la declaración confirmada.');
        abort_unless($solicitud->acuerdo_comercial_aceptado, 422, 'La solicitud todavía no tiene aceptado el acuerdo comercial inicial.');

        $solicitud->update([
            'estado' => 'aprobada',
            'aprobado_at' => now(),
        ]);

        $conversation = Conversacion::firstOrCreate(
            ['cliente_id' => $solicitud->cliente_id, 'solicitud_id' => $solicitud->id],
            [
                'asunto' => 'Seguimiento de '.$solicitud->codigo,
                'estado' => 'abierta',
                'ultimo_mensaje_at' => now(),
            ]
        );

        $messageText = 'Tu solicitud '.$solicitud->codigo.' fue aprobada. AGR Studio continuará contigo por este buzón para orientarte, coordinar el acceso y preparar el inicio del proyecto.';
        $message = Mensaje::create([
            'conversacion_id' => $conversation->id,
            'usuario_id' => $request->user()->id,
            'tipo' => 'texto',
            'mensaje' => $messageText,
        ]);
        $conversation->update(['ultimo_mensaje_at' => now()]);

        if ($solicitud->cliente?->usuario?->id) {
            FirebasePush::sendToUsers(
                [$solicitud->cliente->usuario->id],
                'Solicitud VITI aprobada',
                $messageText,
                [
                    'type' => 'buzon',
                    'contexto' => 'viti',
                    'conversation_id' => $conversation->id,
                    'path' => '/mi-buzon?c='.$conversation->id,
                ]
            );
        }

        Audit::log(
            $request,
            'solicitud_aprobada',
            $solicitud,
            'AGR Studio aprobó la solicitud y envió una orientación inicial por el buzón privado.'
        );

        $access = app(AccessInvitationService::class)->snapshotForSolicitud($solicitud->fresh());

        return response()->json([
            'message' => 'Solicitud aprobada y orientación inicial enviada al buzón del cliente.',
            'data' => [
                'solicitud' => $solicitud->fresh()->load(['empresa','cliente','planViti']),
                'acceso' => $access,
                'conversacion_id' => $conversation->id,
                'mensaje_id' => $message->id,
            ],
        ]);
    }
}
