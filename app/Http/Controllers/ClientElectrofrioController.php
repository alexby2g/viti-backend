<?php

namespace App\Http\Controllers;

use App\Models\Aplicacion;
use App\Services\{SubscriptionAccessService,TenantContext};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientElectrofrioController extends Controller
{
    private function appFor(Request $request): Aplicacion
    {
        $empresa = app(TenantContext::class)->resolve($request);
        $app = Aplicacion::query()
            ->where('empresa_id', $empresa->id)
            ->whereHas('catalogo', fn ($query) => $query->where('clave', 'electrofrio'))
            ->with(['empresa.planViti', 'proyecto', 'catalogo', 'suscripcion'])
            ->latest()
            ->first();

        abort_unless($app, 404, 'Este negocio no tiene Electrofrío asignado.');
        abort_unless((bool) $app->acceso_cliente, 403, 'Electrofrío todavía no fue entregado a este negocio.');
        abort_unless($app->estado === 'activo', 403, 'El acceso a Electrofrío está suspendido.');
        app(SubscriptionAccessService::class)->assertCanUse($app);

        return $app;
    }

    private function forward(Request $request, string $method, int ...$ids): JsonResponse
    {
        $app = $this->appFor($request);
        $request->merge(['empresa_id' => (int) $app->empresa_id]);

        return app(ElectrofrioController::class)->{$method}(
            $request,
            app(TenantContext::class),
            ...$ids
        );
    }

    public function estado(Request $request): JsonResponse
    {
        $app = $this->appFor($request);

        return response()->json(['data' => [
            'empresa' => $app->empresa,
            'aplicacion' => $app,
            'plan' => $app->empresa->planViti ? [
                'codigo' => $app->empresa->planViti->codigo,
                'nombre' => $app->empresa->planViti->nombre,
                'precio_proyecto' => $app->empresa->planViti->precio_proyecto,
                'modulos' => $app->empresa->planViti->modulos,
            ] : null,
            'proyecto' => $app->proyecto ? [
                'codigo' => $app->proyecto->codigo,
                'nombre' => $app->proyecto->nombre,
                'estado' => $app->proyecto->estado,
                'fase' => $app->proyecto->fase,
                'progreso' => $app->proyecto->progreso,
            ] : null,
        ]]);
    }

    public function resumen(Request $request): JsonResponse { return $this->forward($request, 'resumen'); }
    public function clientes(Request $request): JsonResponse { return $this->forward($request, 'clientes'); }
    public function guardarCliente(Request $request): JsonResponse { return $this->forward($request, 'guardarCliente'); }
    public function actualizarCliente(Request $request, int $id): JsonResponse { return $this->forward($request, 'actualizarCliente', $id); }
    public function eliminarCliente(Request $request, int $id): JsonResponse { return $this->forward($request, 'eliminarCliente', $id); }
    public function equipos(Request $request): JsonResponse { return $this->forward($request, 'equipos'); }
    public function guardarEquipo(Request $request): JsonResponse { return $this->forward($request, 'guardarEquipo'); }
    public function actualizarEquipo(Request $request, int $id): JsonResponse { return $this->forward($request, 'actualizarEquipo', $id); }
    public function eliminarEquipo(Request $request, int $id): JsonResponse { return $this->forward($request, 'eliminarEquipo', $id); }
    public function tecnicos(Request $request): JsonResponse { return $this->forward($request, 'tecnicos'); }
    public function guardarTecnico(Request $request): JsonResponse { return $this->forward($request, 'guardarTecnico'); }
    public function actualizarTecnico(Request $request, int $id): JsonResponse { return $this->forward($request, 'actualizarTecnico', $id); }
    public function eliminarTecnico(Request $request, int $id): JsonResponse { return $this->forward($request, 'eliminarTecnico', $id); }
    public function materiales(Request $request): JsonResponse { return $this->forward($request, 'materiales'); }
    public function guardarMaterial(Request $request): JsonResponse { return $this->forward($request, 'guardarMaterial'); }
    public function actualizarMaterial(Request $request, int $id): JsonResponse { return $this->forward($request, 'actualizarMaterial', $id); }
    public function eliminarMaterial(Request $request, int $id): JsonResponse { return $this->forward($request, 'eliminarMaterial', $id); }
    public function ordenes(Request $request): JsonResponse { return $this->forward($request, 'ordenes'); }
    public function guardarOrden(Request $request): JsonResponse { return $this->forward($request, 'guardarOrden'); }
    public function actualizarOrden(Request $request, int $id): JsonResponse { return $this->forward($request, 'actualizarOrden', $id); }
    public function eliminarOrden(Request $request, int $id): JsonResponse { return $this->forward($request, 'eliminarOrden', $id); }
    public function decision(Request $request, int $id): JsonResponse { return $this->forward($request, 'decision', $id); }
    public function finalizar(Request $request, int $id): JsonResponse { return $this->forward($request, 'finalizar', $id); }
    public function usarMaterial(Request $request, int $id): JsonResponse { return $this->forward($request, 'usarMaterial', $id); }
    public function quitarMaterial(Request $request, int $orderId, int $materialId): JsonResponse { return $this->forward($request, 'quitarMaterial', $orderId, $materialId); }
    public function registrarPago(Request $request, int $id): JsonResponse { return $this->forward($request, 'registrarPago', $id); }
    public function pagos(Request $request): JsonResponse { return $this->forward($request, 'pagos'); }
    public function garantias(Request $request): JsonResponse { return $this->forward($request, 'garantias'); }
    public function historial(Request $request): JsonResponse { return $this->forward($request, 'historial'); }
}
