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

    public function confirmCreateRequest(Request $request, AgrActionWorkflowService $workflow, SolicitudController $solicitudController): JsonResponse
    {
        $data = $request->validate([
            'empresa_id' => ['required', 'integer', 'exists:empresas,id'],
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'titulo' => ['required', 'string', 'max:200'],
            'resumen' => ['nullable', 'string', 'max:5000'],
            'prioridad' => ['nullable', 'in:baja,normal,alta,urgente'],
            'fecha_limite_deseada' => ['nullable', 'date'],
            'presupuesto_estimado' => ['nullable', 'numeric', 'min:0'],
            'forma_pago_preferida' => ['nullable', 'in:contado,50_50,tres_partes,por_definir'],
            'frecuencia_suscripcion_preferida' => ['nullable', 'in:mensual,anual'],
        ]);

        $forwarded = Request::create('/api/v1/solicitudes', 'POST', $data);
        $forwarded->setUserResolver($request->getUserResolver());
        $forwarded->headers->set('Accept', 'application/json');

        $response = $solicitudController->store($forwarded);
        $workflow->clear();

        return $response;
    }
}
