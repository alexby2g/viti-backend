<?php

namespace App\Http\Controllers;

use App\Services\AgrAutopilotService;
use App\Services\AgrDecisionEngineService;
use App\Services\AgrPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgrDecisionController extends Controller
{
    public function __invoke(
        Request $request,
        AgrAutopilotService $autopilot,
        AgrDecisionEngineService $decision,
        AgrPermissionService $permissions
    ): JsonResponse {
        abort_unless($permissions->can('read_data'), 403, 'AGR no tiene permiso de lectura.');

        $snapshot = $autopilot->latest();
        if (!$snapshot) {
            $snapshot = $autopilot->run($permissions);
        }

        $result = $decision->evaluate($snapshot);

        return response()->json([
            'agr_decision' => $result,
            'generated_from' => $snapshot['generated_at'] ?? null,
        ]);
    }
}
