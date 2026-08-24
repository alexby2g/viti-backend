<?php

namespace App\Http\Controllers;

use App\Models\{Conversacion,SolicitudSistema};
use App\Services\{AccessInvitationService,WorkflowStateService};
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SolicitudDecisionController extends Controller
{
    public function approve(Request $request, SolicitudSistema $solicitud, WorkflowStateService $workflow): JsonResponse
    {
        $solicitud->loadMissing(['cliente','empresa','planViti']);

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

        Conversacion::firstOrCreate(
            ['cliente_id' => $solicitud->cliente_id, 'solicitud_id' => $solicitud->id],
            ['asunto' => 'Seguimiento de '.$solicitud->codigo, 'estado' => 'abierta', 'ultimo_mensaje_at' => now()]
        );

        Audit::log(
            $request,
            'solicitud_aprobada',
            $solicitud,
            'AGR Studio aprobó la solicitud para continuar con orientación, acceso y desarrollo.'
        );

        $access = app(AccessInvitationService::class)->snapshotForSolicitud($solicitud->fresh());

        return response()->json([
            'message' => 'Solicitud aprobada. El siguiente paso es orientar al cliente y generar su invitación de acceso.',
            'data' => [
                'solicitud' => $solicitud->fresh()->load(['empresa','cliente','planViti']),
                'acceso' => $access,
            ],
        ]);
    }
}
