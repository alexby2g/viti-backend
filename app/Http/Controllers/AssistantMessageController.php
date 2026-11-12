<?php

namespace App\Http\Controllers;

use App\Services\{AgrAssistantService, AgrMemoryService, AgrProjectConversionService};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint conversacional para el asistente web de VITI.
 *
 * Mantiene el motor actual de AGR mientras se prepara una integracion
 * independiente con IRENET. No ejecuta acciones del equipo Windows.
 */
class AssistantMessageController extends Controller
{
    public function __invoke(
        Request $request,
        AgrAssistantService $assistant,
        AgrMemoryService $memory,
        AgrProjectConversionService $projectConversion
    ): JsonResponse {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
        ]);

        $message = trim($data['message']);
        if ($message === '') {
            return response()->json(['message' => 'El mensaje no puede estar vacío.'], 422);
        }

        $conversion = $projectConversion->handle($message);
        $response = $conversion ?? $memory->handle($message, $assistant);

        return response()->json($response)->header('Cache-Control', 'no-store');
    }
}
