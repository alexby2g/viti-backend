<?php

namespace App\Services;

use App\Models\{AlertaSaas,Mantenimiento,Proyecto,SolicitudSistema,Suscripcion};
use Illuminate\Support\Collection;

class AgrSituationService
{
    public function snapshot(): array
    {
        $requests = SolicitudSistema::query()
            ->whereIn('estado', ['en_revision', 'aprobada'])
            ->with(['empresa:id,nombre_comercial', 'planViti:id,nombre'])
            ->latest('id')
            ->limit(20)
            ->get(['id','codigo','empresa_id','plan_viti_id','estado','prioridad','enviado_at']);

        $projects = Proyecto::query()
            ->whereIn('estado', ['activo', 'pausado'])
            ->with(['empresa:id,nombre_comercial'])
            ->latest('updated_at')
            ->limit(20)
            ->get(['id','codigo','empresa_id','nombre','fase','estado','progreso','updated_at']);

        $support = Mantenimiento::query()
            ->whereNotIn('estado', ['resuelto', 'cerrado'])
            ->latest('updated_at')
            ->limit(20)
            ->get(['id','codigo','empresa_id','estado','prioridad','titulo','updated_at']);

        $payments = Suscripcion::query()
            ->whereIn('estado', ['gracia', 'suspendida'])
            ->latest('updated_at')
            ->limit(20)
            ->get(['id','empresa_id','estado','fecha_fin','updated_at']);

        $alerts = AlertaSaas::query()
            ->whereNull('leida_at')
            ->latest('created_at')
            ->limit(20)
            ->get(['id','usuario_id','empresa_id','tipo','titulo','mensaje','ruta','created_at']);

        return [
            'resumen' => [
                'solicitudes_en_revision' => SolicitudSistema::where('estado', 'en_revision')->count(),
                'solicitudes_aprobadas' => SolicitudSistema::where('estado', 'aprobada')->count(),
                'proyectos_activos' => Proyecto::where('estado', 'activo')->count(),
                'proyectos_pausados' => Proyecto::where('estado', 'pausado')->count(),
                'pagos_con_atencion' => Suscripcion::whereIn('estado', ['gracia', 'suspendida'])->count(),
                'soportes_abiertos' => Mantenimiento::whereNotIn('estado', ['resuelto', 'cerrado'])->count(),
                'alertas_no_leidas' => AlertaSaas::whereNull('leida_at')->count(),
            ],
            'solicitudes' => $requests->map(fn ($item) => [
                'id' => $item->id,
                'codigo' => $item->codigo,
                'empresa' => $item->empresa?->nombre_comercial,
                'plan' => $item->planViti?->nombre,
                'estado' => $item->estado,
                'prioridad' => $item->prioridad,
                'enviado_at' => $item->enviado_at,
            ])->values(),
            'proyectos' => $projects->map(fn ($item) => [
                'id' => $item->id,
                'codigo' => $item->codigo,
                'empresa' => $item->empresa?->nombre_comercial,
                'nombre' => $item->nombre,
                'fase' => $item->fase,
                'estado' => $item->estado,
                'progreso' => (int) $item->progreso,
                'updated_at' => $item->updated_at,
            ])->values(),
            'soportes' => $support->map(fn ($item) => [
                'id' => $item->id,
                'codigo' => $item->codigo,
                'empresa_id' => $item->empresa_id,
                'estado' => $item->estado,
                'prioridad' => $item->prioridad,
                'titulo' => $item->titulo,
                'updated_at' => $item->updated_at,
            ])->values(),
            'pagos' => $payments->map(fn ($item) => [
                'id' => $item->id,
                'empresa_id' => $item->empresa_id,
                'estado' => $item->estado,
                'fecha_fin' => $item->fecha_fin,
                'updated_at' => $item->updated_at,
            ])->values(),
            'alertas' => $alerts->map(fn ($item) => [
                'id' => $item->id,
                'usuario_id' => $item->usuario_id,
                'empresa_id' => $item->empresa_id,
                'tipo' => $item->tipo,
                'titulo' => $item->titulo,
                'mensaje' => $item->mensaje,
                'ruta' => $item->ruta,
                'created_at' => $item->created_at,
            ])->values(),
            'prioridad_sugerida' => $this->priority($requests, $projects, $support, $payments, $alerts),
            'meta' => [
                'source' => 'agr_situation_local',
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    private function priority(Collection $requests, Collection $projects, Collection $support, Collection $payments, Collection $alerts): array
    {
        $items = [];

        foreach ($requests->whereIn('prioridad', ['urgente', 'alta']) as $item) {
            $items[] = ['tipo' => 'solicitud', 'codigo' => $item->codigo, 'motivo' => 'Solicitud con prioridad '.$item->prioridad.'.', 'ruta' => '/solicitudes/'.$item->id];
        }

        foreach ($projects->where('estado', 'pausado') as $item) {
            $items[] = ['tipo' => 'proyecto', 'codigo' => $item->codigo, 'motivo' => 'Proyecto pausado; requiere revisión antes de continuar.', 'ruta' => '/proyectos/'.$item->id];
        }

        foreach ($payments as $item) {
            $items[] = ['tipo' => 'pago', 'codigo' => (string) $item->id, 'motivo' => 'Suscripción en estado '.$item->estado.'.', 'ruta' => '/pagos'];
        }

        foreach ($support->whereIn('prioridad', ['urgente', 'alta']) as $item) {
            $items[] = ['tipo' => 'soporte', 'codigo' => $item->codigo ?: (string) $item->id, 'motivo' => 'Soporte con prioridad '.$item->prioridad.'.', 'ruta' => '/mantenimientos'];
        }

        foreach ($alerts->take(5) as $item) {
            $items[] = ['tipo' => 'alerta', 'codigo' => (string) $item->id, 'motivo' => $item->titulo, 'ruta' => $item->ruta];
        }

        return collect($items)->take(12)->values()->all();
    }
}
