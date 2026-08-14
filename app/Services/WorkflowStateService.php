<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class WorkflowStateService
{
    private const SOLICITUD_TRANSITIONS = [
        'borrador' => ['en_revision', 'rechazada', 'cerrada'],
        'en_revision' => ['aprobada', 'rechazada', 'cerrada'],
        'aprobada' => ['convertida', 'cerrada'],
        'rechazada' => ['en_revision', 'cerrada'],
        'convertida' => ['cerrada'],
        'cerrada' => [],
    ];

    private const PROYECTO_PHASE_TRANSITIONS = [
        'levantamiento' => ['analisis'],
        'analisis' => ['diseno'],
        'diseno' => ['desarrollo'],
        'desarrollo' => ['beta'],
        'beta' => ['pruebas'],
        'pruebas' => ['ajustes', 'implementacion'],
        'ajustes' => ['implementacion'],
        'implementacion' => ['finalizado'],
        'finalizado' => ['mantenimiento'],
        'mantenimiento' => ['finalizado'],
    ];

    private const PROYECTO_STATE_TRANSITIONS = [
        'activo' => ['pausado', 'finalizado', 'cancelado', 'mantenimiento'],
        'pausado' => ['activo', 'cancelado'],
        'finalizado' => ['mantenimiento'],
        'mantenimiento' => ['activo', 'finalizado'],
        'cancelado' => [],
    ];

    public function assertSolicitudTransition(?string $current, ?string $target): void
    {
        $this->assertTransition('solicitud', $current, $target, self::SOLICITUD_TRANSITIONS);
    }

    public function assertProyectoStateTransition(?string $current, ?string $target): void
    {
        $this->assertTransition('proyecto', $current, $target, self::PROYECTO_STATE_TRANSITIONS);
    }

    public function assertProyectoPhaseTransition(?string $current, ?string $target): void
    {
        $this->assertTransition('fase de proyecto', $current, $target, self::PROYECTO_PHASE_TRANSITIONS, 'fase');
    }

    public function solicitudStates(): array { return array_keys(self::SOLICITUD_TRANSITIONS); }
    public function proyectoPhases(): array { return array_keys(self::PROYECTO_PHASE_TRANSITIONS); }
    public function proyectoStates(): array { return array_keys(self::PROYECTO_STATE_TRANSITIONS); }

    public function nextSolicitudStates(?string $current): array
    {
        return $current && isset(self::SOLICITUD_TRANSITIONS[$current]) ? self::SOLICITUD_TRANSITIONS[$current] : [];
    }

    public function nextProyectoPhases(?string $current): array
    {
        return $current && isset(self::PROYECTO_PHASE_TRANSITIONS[$current]) ? self::PROYECTO_PHASE_TRANSITIONS[$current] : [];
    }

    public function nextProyectoStates(?string $current): array
    {
        return $current && isset(self::PROYECTO_STATE_TRANSITIONS[$current]) ? self::PROYECTO_STATE_TRANSITIONS[$current] : [];
    }

    public function solicitudSnapshot(?string $current): array
    {
        return ['actual'=>$current,'permitidos'=>$this->nextSolicitudStates($current),'catalogo'=>$this->solicitudStates()];
    }

    public function proyectoSnapshot(?string $fase, ?string $estado): array
    {
        return [
            'fase'=>['actual'=>$fase,'permitidos'=>$this->nextProyectoPhases($fase),'catalogo'=>$this->proyectoPhases()],
            'estado'=>['actual'=>$estado,'permitidos'=>$this->nextProyectoStates($estado),'catalogo'=>$this->proyectoStates()],
        ];
    }

    private function assertTransition(string $entity, ?string $current, ?string $target, array $transitions, string $field='estado'): void
    {
        if (!$current || !$target || $current === $target) return;
        if (!isset($transitions[$current]) || !array_key_exists($target, $transitions)) {
            throw ValidationException::withMessages([$field => "El valor indicado no pertenece al flujo oficial de {$entity} en VITI."]);
        }
        if (!in_array($target, $transitions[$current], true)) {
            throw ValidationException::withMessages([$field => "La transición de {$entity} de {$current} a {$target} no está permitida."]);
        }
    }
}
