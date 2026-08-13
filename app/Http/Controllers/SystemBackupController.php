<?php

namespace App\Http\Controllers;

use App\Models\SystemBackup;
use App\Services\DatabaseBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SystemBackupController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => SystemBackup::query()->latest()->limit(12)->get()->map(fn (SystemBackup $backup) => $this->payload($backup)),
            'policy' => [
                'automatic' => false,
                'restore_from_ui' => false,
                'message' => 'Los respaldos pueden crearse y verificarse desde VITI. La restauración se mantiene fuera de la interfaz por seguridad.',
            ],
        ]);
    }

    public function store(Request $request, DatabaseBackupService $service): JsonResponse
    {
        try {
            $backup = $service->create('manual', $request->user()?->id);
            return response()->json([
                'message' => 'Respaldo creado y verificado correctamente.',
                'data' => $this->payload($backup),
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'No se pudo completar el respaldo. Revisa el Centro de Salud para ver el último intento.',
            ], 500);
        }
    }

    public function verify(SystemBackup $backup, DatabaseBackupService $service): JsonResponse
    {
        try {
            $backup = $service->verify($backup);
            return response()->json([
                'message' => 'Integridad del respaldo confirmada.',
                'data' => $this->payload($backup),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'El respaldo no superó la verificación de integridad.',
                'data' => $this->payload($backup->fresh()),
            ], 422);
        }
    }

    private function payload(SystemBackup $backup): array
    {
        return [
            'id' => $backup->id,
            'status' => $backup->status,
            'source' => $backup->source,
            'size_bytes' => $backup->size_bytes,
            'checksum_sha256' => $backup->checksum_sha256,
            'started_at' => $backup->started_at?->toIso8601String(),
            'completed_at' => $backup->completed_at?->toIso8601String(),
            'verified_at' => $backup->verified_at?->toIso8601String(),
            'failure_reason' => $backup->failure_reason,
        ];
    }
}
