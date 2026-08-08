<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,PeluqueriaAtencion,PeluqueriaCita,PeluqueriaCliente,PeluqueriaPago,PeluqueriaPersonal,PeluqueriaServicio,Proyecto};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientAppsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clienteId = (int) $request->user()->cliente_id;

        $projects = Proyecto::query()
            ->where('cliente_id', $clienteId)
            ->whereHas('aplicacion')
            ->with(['empresa:id,nombre_comercial', 'aplicacion'])
            ->latest()
            ->get();

        $items = $projects->map(function (Proyecto $project): array {
            $app = $project->aplicacion;
            $isPeluqueria = $app && str_contains(mb_strtolower((string) $app->nombre), 'peluquer');

            return [
                'id' => $app?->id,
                'nombre' => $app?->nombre,
                'slug' => $app?->slug,
                'version' => $app?->version,
                'entorno' => $app?->entorno,
                'estado' => $app?->estado,
                'acceso_cliente' => (bool) $app?->acceso_cliente,
                'entregado_at' => $app?->entregado_at,
                'empresa' => $project->empresa,
                'proyecto' => [
                    'codigo' => $project->codigo,
                    'nombre' => $project->nombre,
                    'fase' => $project->fase,
                    'estado' => $project->estado,
                    'progreso' => $project->progreso,
                ],
                'ruta' => $isPeluqueria && $app?->acceso_cliente ? '/mi-apps/peluqueria/inicio' : null,
            ];
        })->values();

        return response()->json(['data' => $items]);
    }

    public function peluqueria(Request $request): JsonResponse
    {
        $clienteId = (int) $request->user()->cliente_id;

        $project = Proyecto::query()
            ->where('cliente_id', $clienteId)
            ->whereHas('aplicacion', fn ($q) => $q->where('nombre', 'like', '%Peluquer%'))
            ->with(['empresa:id,nombre_comercial,actividad,ciudad,direccion', 'aplicacion'])
            ->latest()
            ->first();

        abort_unless($project && $project->empresa_id, 404, 'No tienes una aplicación de peluquería asignada.');
        abort_unless((bool) $project->aplicacion?->acceso_cliente, 403, 'Tu aplicación todavía no fue entregada. Contacta con Atención VITI.');

        $empresaId = (int) $project->empresa_id;
        $app = $project->aplicacion;

        $today = now()->toDateString();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $summary = [
            'clientes' => PeluqueriaCliente::query()->where('empresa_id', $empresaId)->where('activo', true)->count(),
            'servicios' => PeluqueriaServicio::query()->where('empresa_id', $empresaId)->where('activo', true)->count(),
            'personal' => PeluqueriaPersonal::query()->where('empresa_id', $empresaId)->where('activo', true)->count(),
            'citas_hoy' => PeluqueriaCita::query()->where('empresa_id', $empresaId)->whereDate('fecha', $today)->count(),
            'atenciones_mes' => PeluqueriaAtencion::query()->where('empresa_id', $empresaId)->whereBetween('finalizada_at', [$monthStart, $monthEnd])->count(),
            'ingresos_mes' => (float) PeluqueriaPago::query()->where('empresa_id', $empresaId)->whereBetween('pagado_at', [$monthStart, $monthEnd])->sum('monto'),
        ];

        $appointments = PeluqueriaCita::query()
            ->where('empresa_id', $empresaId)
            ->with(['cliente:id,nombre,telefono', 'servicio:id,nombre,precio,duracion_minutos', 'personal:id,nombre,especialidad'])
            ->orderByDesc('fecha')
            ->orderByDesc('hora_inicio')
            ->limit(8)
            ->get();

        $recent = PeluqueriaAtencion::query()
            ->where('empresa_id', $empresaId)
            ->with(['cliente:id,nombre', 'servicio:id,nombre', 'personal:id,nombre', 'pagos'])
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return response()->json(['data' => [
            'aplicacion' => [
                'nombre' => $app?->nombre ?? 'Peluquería VITI',
                'version' => $app?->version,
                'entorno' => $app?->entorno ?? 'produccion',
                'estado' => $app?->estado ?? 'activo',
                'acceso_cliente' => (bool) $app?->acceso_cliente,
                'entregado_at' => $app?->entregado_at,
            ],
            'empresa' => $project->empresa,
            'proyecto' => [
                'codigo' => $project->codigo,
                'nombre' => $project->nombre,
                'fase' => $project->fase,
                'estado' => $project->estado,
                'progreso' => $project->progreso,
                'fecha_inicio' => $project->fecha_inicio,
                'fecha_entrega' => $project->fecha_entrega,
            ],
            'resumen' => $summary,
            'citas' => $appointments,
            'atenciones' => $recent,
        ]]);
    }
}
