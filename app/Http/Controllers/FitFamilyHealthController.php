<?php

namespace App\Http\Controllers;

use App\Services\FitFamilyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class FitFamilyHealthController extends Controller
{
    public function __invoke(Request $request, FitFamilyContext $context): JsonResponse
    {
        $databaseOk = false;
        try {
            DB::select('select 1');
            $databaseOk = true;
        } catch (Throwable) {
            $databaseOk = false;
        }

        $tenantOk = false;
        $applicationOk = false;

        if ($databaseOk) {
            try {
                $empresa = $context->resolve($request);
                $tenantOk = (bool) $empresa;
                $applicationOk = $tenantOk && $empresa->aplicaciones()
                    ->whereHas('catalogo', fn ($q) => $q->where('clave', config('fitfamily.catalog_key')))
                    ->exists();
            } catch (Throwable) {
                $tenantOk = false;
                $applicationOk = false;
            }
        }

        $healthy = $databaseOk && $tenantOk && $applicationOk;

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'service' => 'VITI FitFamily',
            'checks' => [
                'api' => true,
                'database' => $databaseOk,
                'tenant' => $tenantOk,
                'application' => $applicationOk,
            ],
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
