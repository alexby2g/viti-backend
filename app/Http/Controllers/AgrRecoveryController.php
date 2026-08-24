<?php

namespace App\Http\Controllers;

use App\Services\{AgrActivityService,AgrAutopilotService,AgrIncidentService,AgrPermissionService,AgrRecoveryService,AgrSystemGuardService};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgrRecoveryController extends Controller
{
    public function plans(AgrIncidentService $incidents, AgrRecoveryService $recovery): JsonResponse
    {
        $items = collect($incidents->active())
            ->map(fn (array $incident) => [
                'incident' => $incident,
                'plans' => $recovery->plans($incident),
            ])->values();

        return response()->json(['incidents' => $items]);
    }

    public function execute(
        Request $request,
        AgrIncidentService $incidents,
        AgrRecoveryService $recovery,
        AgrSystemGuardService $guard,
        AgrAutopilotService $autopilot,
        AgrPermissionService $permissions,
        AgrActivityService $activity
    ): JsonResponse {
        $data = $request->validate([
            'incident_id' => ['required', 'string', 'max:40'],
            'action' => ['required', 'string', 'in:recheck_system,refresh_agr_state'],
        ]);

        if (!$recovery->canExecute($data['action'])) {
            return response()->json(['message' => 'AGR no permite esa recuperación.'], 422);
        }

        if (!$permissions->can('prepare_actions')) {
            return response()->json(['message' => 'El nivel actual de AGR no permite ejecutar esta recuperación.'], 403);
        }

        $incident = collect($incidents->active())->firstWhere('id', $data['incident_id']);
        if (!$incident) {
            return response()->json(['message' => 'El incidente ya no está activo o no existe.'], 404);
        }

        $result = match ($data['action']) {
            'recheck_system' => $guard->scan(),
            'refresh_agr_state' => $autopilot->run($permissions),
        };

        $activity->record('recovery_action', 'AGR ejecutó una recuperación segura', $incident['title'].' · '.$data['action'], [
            'incident_id' => $incident['id'],
            'action' => $data['action'],
        ]);

        return response()->json([
            'message' => 'Recuperación segura ejecutada y verificada.',
            'incident' => $incident,
            'action' => $data['action'],
            'result' => $result,
        ]);
    }
}
