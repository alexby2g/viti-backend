<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MonitorController extends Controller
{
    public function health(Request $request): JsonResponse
    {
        $checks = [
            'api' => ['status' => 'ok', 'message' => 'VITI Core API responde correctamente.'],
            'database' => $this->databaseCheck(),
            'storage' => $this->storageCheck(),
        ];

        $failed = collect($checks)->where('status', '!=', 'ok')->count();
        $status = $failed === 0 ? 'healthy' : ($failed === count($checks) ? 'critical' : 'attention');

        return response()->json([
            'service' => 'VITI Core API',
            'status' => $status,
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
            'summary' => $status === 'healthy'
                ? 'Todos los servicios básicos responden correctamente.'
                : "Hay {$failed} comprobación(es) que requieren atención.",
        ], $status === 'critical' ? 503 : 200);
    }

    private function databaseCheck(): array
    {
        $started = microtime(true);

        try {
            DB::select('select 1');
            return [
                'status' => 'ok',
                'message' => 'PostgreSQL responde correctamente.',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (\Throwable $e) {
            report($e);
            return [
                'status' => 'error',
                'message' => 'La conexión con la base de datos no responde.',
            ];
        }
    }

    private function storageCheck(): array
    {
        try {
            $disk = config('filesystems.default', 'local');
            Storage::disk($disk)->put('health/.keep', 'ok');
            Storage::disk($disk)->delete('health/.keep');

            return [
                'status' => 'ok',
                'message' => 'El almacenamiento configurado permite lectura y escritura.',
                'disk' => $disk,
            ];
        } catch (\Throwable $e) {
            report($e);
            return [
                'status' => 'error',
                'message' => 'El almacenamiento configurado no responde correctamente.',
            ];
        }
    }
}
