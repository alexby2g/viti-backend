<?php

namespace App\Http\Controllers;

use App\Services\AgrAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgrAssistantController extends Controller
{
    public function __invoke(Request $request, AgrAssistantService $assistant): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:500'],
        ]);

        return response()->json($assistant->handle($validated['message']));
    }
}
