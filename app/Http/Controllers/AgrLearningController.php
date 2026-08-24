<?php

namespace App\Http\Controllers;

use App\Services\AgrLearningMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgrLearningController extends Controller
{
    public function __invoke(Request $request, AgrLearningMemoryService $learning): JsonResponse
    {
        abort_unless($request->user(), 401);

        if ($request->filled('record_id') && $request->filled('outcome')) {
            $updated = $learning->updateOutcome(
                (string) $request->input('record_id'),
                (string) $request->input('outcome'),
                $request->input('note') !== null ? (string) $request->input('note') : null,
            );

            if (!$updated) {
                return response()->json(['message' => 'Registro de aprendizaje no encontrado o resultado inválido.'], 422);
            }

            return response()->json(['data' => $updated, 'summary' => $learning->summary()]);
        }

        return response()->json([
            'summary' => $learning->summary(),
            'records' => $learning->latest((int) $request->input('limit', 30)),
        ]);
    }
}
