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
        // Si QA no encuentra correcciones pendientes, puede pasar directo a implementación.
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
        if (!$current || !$target || $current === $target) return;
        if (!isset(self::PROYECTO_PHASE_TRANSITIONS[$current]) || !array_key_exists($target, self::PROYECTO_PHASE_TRANSITIONS)) {
            throw ValidationException::withMessages(['fase' => 'La fase indicada no pertenece al flujo oficial de VITI.']);
        }
        if (!in_array($target, self::PROYECTO_PHASE_TRANSITIONS[$current], true)) {
            throw ValidationException::withMessages([
                'fase' => "La transición de fase de {$current} a {$target} no está permitida. Registra cada etapa para conservar un historial confiable.",
            ]);
        }
    }

    public function solicitudStates(): array
    {
        return array_keys(self::SOLICITUD_TRANSITIONS);
    }

    public function proyectoPhases(): array
    {
        return array_keys(self::PROYECTO_PHASE_TRANSITIONS);
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
