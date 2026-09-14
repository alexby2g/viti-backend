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
        abort_unless($solicitud->cliente?->usuario || filled($solicitud->cliente?->correo), 422, 'Registra un correo válido del responsable antes de aprobar; VITI lo necesita para habilitar su acceso.');

        $solicitud->update([
            'estado' => 'aprobada',
            'aprobado_at' => now(),
        ]);
        // Recién al aprobar la solicitud el prospecto pasa a formar parte del trabajo activo.
        if ($solicitud->empresa && $solicitud->empresa->estado === 'pendiente_revision') {
            $solicitud->empresa->update(['estado' => 'levantamiento']);
        }

        $conversation = Conversacion::firstOrCreate(
            ['cliente_id' => $solicitud->cliente_id, 'solicitud_id' => $solicitud->id],
            [
                'asunto' => 'Seguimiento de '.$solicitud->codigo,
                'estado' => 'abierta',
                'ultimo_mensaje_at' => now(),
            ]
        );

        $messageText = 'Tu solicitud '.$solicitud->codigo.' fue aprobada. VITI continuará contigo por este espacio para orientarte, coordinar el acceso y preparar el inicio del proyecto.';
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
            'VITI aprobó la solicitud y envió una orientación inicial por el buzón privado.'
        );

        $accessService = app(AccessInvitationService::class);
        $freshSolicitud = $solicitud->fresh()->loadMissing(['cliente.usuario','empresa','planViti']);
        $access = $freshSolicitud->cliente?->usuario
            ? $accessService->snapshotForSolicitud($freshSolicitud)
            : $accessService->createAndSend($freshSolicitud, $request->user(), 7);

        Audit::log(
            $request,
            'acceso_cliente_habilitado',
            $solicitud,
            $access['tiene_cuenta'] ?? false
                ? 'La solicitud quedó aprobada y el responsable ya tenía una cuenta VITI activa.'
                : 'La solicitud quedó aprobada y VITI generó automáticamente la invitación de acceso del responsable.'
        );

        return response()->json([
            'message' => ($access['tiene_cuenta'] ?? false)
                ? 'Solicitud aprobada. El responsable ya tiene acceso a VITI y el proyecto puede iniciarse.'
                : (($access['email_enviado'] ?? false)
                    ? 'Solicitud aprobada e invitación enviada al correo del responsable.'
                    : 'Solicitud aprobada e invitación generada. El correo no pudo enviarse automáticamente; copia el enlace desde la solicitud.'),
            'data' => [
                'solicitud' => $solicitud->fresh()->load(['empresa','cliente','planViti']),
                'acceso' => $access,
                'conversacion_id' => $conversation->id,
                'mensaje_id' => $message->id,
            ],
        ]);
    }
}
