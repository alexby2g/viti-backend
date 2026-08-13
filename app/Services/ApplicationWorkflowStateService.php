<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ApplicationWorkflowStateService
{
    private const ENVIRONMENTS = ['desarrollo','beta','produccion'];

    private const STATE_TRANSITIONS = [
        'en_pruebas' => ['activo','pausado','retirado'],
        'activo' => ['pausado','retirado'],
        'pausado' => ['activo','retirado'],
        'retirado' => [],
    ];

    public function assertTransition(?string $currentEnvironment, ?string $targetEnvironment, ?string $currentState, ?string $targetState): void
    {
        $this->assertEnvironment($currentEnvironment,$targetEnvironment);
        $this->assertState($currentState,$targetState);
    }

    private function assertEnvironment(?string $current, ?string $target): void
    {
        if (!$current || !$target || $current === $target) return;

        $from = array_search($current,self::ENVIRONMENTS,true);
        $to = array_search($target,self::ENVIRONMENTS,true);

        if ($from === false || $to === false || $to < $from) {
            throw ValidationException::withMessages([
                'entorno'=>'El entorno de la aplicación no puede retroceder. Registra una nueva versión o un ajuste para conservar el historial.',
            ]);
        }
    }

    private function assertState(?string $current, ?string $target): void
    {
        if (!$current || !$target || $current === $target) return;

        if (!isset(self::STATE_TRANSITIONS[$current]) || !array_key_exists($target,self::STATE_TRANSITIONS)) {
            throw ValidationException::withMessages(['estado'=>'El estado indicado no pertenece al ciclo oficial de aplicaciones VITI.']);
        }

        if (!in_array($target,self::STATE_TRANSITIONS[$current],true)) {
            throw ValidationException::withMessages(['estado'=>'La transición solicitada no es válida para el ciclo de la aplicación.']);
        }
    }
}
