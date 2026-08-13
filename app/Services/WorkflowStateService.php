<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class WorkflowStateService
{
    private const SOLICITUD_TRANSITIONS = [
        'borrador' => ['en_revision', 'rechazada', 'cerrada'],
        'en_revision' => ['aprobada', 'rechazada', 'convertida', 'cerrada'],
        'aprobada' => ['convertida', 'cerrada'],
        'rechazada' => ['en_revision', 'cerrada'],
        'convertida' => ['cerrada'],
        'cerrada' => [],
    ];

    private const PROYECTO_PHASES = [
        'levantamiento', 'analisis', 'diseno', 'desarrollo', 'beta',
        'pruebas', 'ajustes', 'implementacion', 'finalizado', 'mantenimiento',
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
        if (!$current || !$target || $current === $target) return;
        $from = array_search($current, self::PROYECTO_PHASES, true);
        $to = array_search($target, self::PROYECTO_PHASES, true);
        if ($from === false || $to === false) {
            throw ValidationException::withMessages(['fase' => 'La fase indicada no pertenece al flujo oficial de VITI.']);
        }
        if ($current === 'mantenimiento' && $target === 'finalizado') return;
        if ($to < $from) {
            throw ValidationException::withMessages(['fase' => 'No se puede retroceder la fase del proyecto. Registra un avance u observación para conservar el historial.']);
        }
    }

    public function solicitudStates(): array
    {
        return array_keys(self::SOLICITUD_TRANSITIONS);
    }

    public function proyectoPhases(): array
    {
        return self::PROYECTO_PHASES;
    }

    public function proyectoStates(): array
    {
        return array_keys(self::PROYECTO_STATE_TRANSITIONS);
    }

    private function assertTransition(string $entity, ?string $current, ?string $target, array $transitions): void
    {
        if (!$current || !$target || $current === $target) return;
        if (!isset($transitions[$current]) || !array_key_exists($target, $transitions)) {
            throw ValidationException::withMessages(['estado' => "El estado indicado no pertenece al flujo oficial de {$entity} en VITI."]);
        }
        if (!in_array($target, $transitions[$current], true)) {
            throw ValidationException::withMessages(['estado' => "La transición de {$entity} de {$current} a {$target} no está permitida."]);
        }
    }
}
