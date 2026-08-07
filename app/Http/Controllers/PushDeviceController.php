<?php

namespace App\Http\Controllers;

use App\Models\PushDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushDeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required','string','max:512'],
            'plataforma' => ['nullable','in:android'],
            'dispositivo' => ['nullable','string','max:120'],
        ], [
            'token.required' => 'No se recibió el identificador del dispositivo.',
        ]);

        $device = PushDevice::updateOrCreate(
            ['token' => $data['token']],
            [
                'usuario_id' => $request->user()->id,
                'plataforma' => $data['plataforma'] ?? 'android',
                'dispositivo' => $data['dispositivo'] ?? null,
                'activo' => true,
                'ultimo_registro_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Dispositivo registrado para notificaciones.',
            'data' => $device,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required','string','max:512'],
        ]);

        PushDevice::query()
            ->where('usuario_id', $request->user()->id)
            ->where('token', $data['token'])
            ->update(['activo' => false]);

        return response()->json(['message' => 'Notificaciones desactivadas en este dispositivo.']);
    }
}
