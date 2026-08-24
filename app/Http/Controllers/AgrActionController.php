<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Services\AgrActionWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgrActionController extends Controller
{
    public function confirmCreateClient(Request $request, AgrActionWorkflowService $workflow): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:180'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'whatsapp' => ['nullable', 'string', 'max:50'],
            'correo' => ['nullable', 'email', 'max:180'],
            'documento' => ['nullable', 'string', 'max:50'],
            'ci_expedido' => ['nullable', 'string', 'max:20'],
            'ciudad' => ['nullable', 'string', 'max:120'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ]);

        $client = Cliente::create([
            ...$data,
            'estado' => 'activo',
            'canal_origen' => 'agr_assistant',
        ]);

        $workflow->clear();

        return response()->json([
            'message' => 'Cliente registrado correctamente desde AGR Assistant.',
            'data' => [
                'client' => $client->only(['id', 'nombre', 'telefono', 'whatsapp', 'correo', 'ciudad', 'estado']),
            ],
        ], 201);
    }
}
