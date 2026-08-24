<?php

namespace App\Http\Controllers;

use App\Services\AgrActivityService;
use App\Services\AgrIncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgrIncidentController extends Controller
{
    public function index(AgrIncidentService $incidents): JsonResponse
    {
        return response()->json(['incidents' => $incidents->active()]);
    }

    public function updateStatus(Request $request, string $incidentId, AgrIncidentService $incidents, AgrActivityService $activity): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:detected,investigating,recommended,in_progress,resolved,closed'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $incident = $incidents->updateStatus($incidentId, $data['status'], $data['note'] ?? null);
        if ($incident === null) {
            return response()->json(['message' => 'No encontré ese incidente activo.'], 404);
        }

        $activity->record('incident_status_changed', 'Incidente actualizado', $incident['title'].' → '.$incident['status'], [
            'incident_id' => $incident['id'],
            'status' => $incident['status'],
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['incident' => $incident]);
    }
}
