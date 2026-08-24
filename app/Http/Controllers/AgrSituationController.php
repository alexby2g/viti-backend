<?php

namespace App\Http\Controllers;

use App\Services\AgrSituationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgrSituationController extends Controller
{
    public function __invoke(Request $request, AgrSituationService $situation): JsonResponse
    {
        return response()->json([
            'data' => $situation->snapshot(),
        ]);
    }
}
