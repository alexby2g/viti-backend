<?php

namespace App\Http\Controllers;

use App\Services\AgrAutopilotService;
use Illuminate\Http\JsonResponse;

class AgrAutopilotController extends Controller
{
    public function status(AgrAutopilotService $autopilot): JsonResponse
    {
        return response()->json([
            'data' => $autopilot->latest(),
            'configured' => (bool) config('agr.autopilot.enabled', true),
        ]);
    }

    public function run(AgrAutopilotService $autopilot): JsonResponse
    {
        return response()->json([
            'data' => $autopilot->run(),
        ]);
    }
}
