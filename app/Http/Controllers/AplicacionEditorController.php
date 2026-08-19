<?php

namespace App\Http\Controllers;

use App\Models\Aplicacion;
use App\Services\FeatureGateService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AplicacionEditorController extends Controller
{
    public function show(Aplicacion $aplicacion, FeatureGateService $features): JsonResponse
    {
        $aplicacion->load(['empresa.planViti','catalogo','suscripcion']);

        return response()->json(['data' => $this->payload($aplicacion, $features)]);
    }

    public function update(Request $request, Aplicacion $aplicacion, FeatureGateService $features): JsonResponse
    {
        $data = $request->validate([
            'heredar_modulos_plan' => ['required','boolean'],
            'modulos' => ['nullable','array'],
            'modulos.*' => ['string','in:inicio,agenda,ordenes,clientes,equipos,tecnicos,inventario,pagos,garantias,historial,buzon'],
            'branding' => ['nullable','array'],
            'branding.nombre' => ['nullable','string','max:200'],
            'branding.logo_url' => ['nullable','url','max:1000'],
            'branding.icono' => ['nullable','string','max:100'],
            'branding.color_principal' => ['nullable','string','max:30'],
            'branding.color_secundario' => ['nullable','string','max:30'],
        ]);

        $planModules = $features->modules($aplicacion->empresa()->with('planViti')->firstOrFail());
        $selected = array_values(array_unique(array_filter(array_map('strval', $data['modulos'] ?? []))));

        if (!$data['heredar_modulos_plan']) {
            abort_unless($planModules === null || count(array_diff($selected, $planModules)) === 0, 422,
                'No puedes habilitar módulos que no pertenecen al plan VITI de la empresa.');
        }

        $current = (array) ($aplicacion->configuracion ?? []);
        $branding = array_merge((array) ($current['branding'] ?? []), (array) ($data['branding'] ?? []));

        $aplicacion->update([
            'configuracion' => [
                ...$current,
                'heredar_modulos_plan' => (bool) $data['heredar_modulos_plan'],
                'modulos' => $selected,
                'branding' => array_filter($branding, fn ($value) => $value !== null && $value !== ''),
                'configurado_at' => now()->toIso8601String(),
            ],
        ]);

        Audit::log($request, 'aplicacion_moldeada', $aplicacion,
            'Se actualizó la configuración independiente de una aplicación: módulos y marca.');

        $aplicacion->load(['empresa.planViti','catalogo','suscripcion']);
        return response()->json([
            'message' => 'Configuración de la aplicación guardada.',
            'data' => $this->payload($aplicacion, $features),
        ]);
    }

    private function payload(Aplicacion $application, FeatureGateService $features): array
    {
        $config = (array) ($application->configuracion ?? []);
        $inherit = !array_key_exists('heredar_modulos_plan', $config) || $config['heredar_modulos_plan'] !== false;
        $planModules = $features->modules($application->empresa);

        return [
            'aplicacion' => $application,
            'catalogo' => $application->catalogo,
            'plan' => $application->empresa->planViti ? [
                'id' => $application->empresa->planViti->id,
                'codigo' => $application->empresa->planViti->codigo,
                'nombre' => $application->empresa->planViti->nombre,
                'modulos' => $planModules,
            ] : null,
            'configuracion' => [
                'heredar_modulos_plan' => $inherit,
                'modulos' => $features->modulesForApp($application) ?? FeatureGateService::MODULES,
                'branding' => $config['branding'] ?? [],
            ],
            'catalogo_modulos' => $features->moduleCatalog(),
        ];
    }
}
