<?php

namespace App\Services;

class AgrRecoveryService
{
    public function plans(array $incident): array
    {
        $key = (string) ($incident['key'] ?? '');

        $plans = match ($key) {
            'technical_integrity' => [
                $this->plan('recheck_system', 'Volver a ejecutar la ronda técnica', 'Recomprobar la salud de VITI antes de tocar cualquier dato.', true, false),
                $this->plan('refresh_agr_state', 'Actualizar el estado interno de AGR', 'Regenerar el snapshot de AGR para eliminar información técnica obsoleta.', true, false),
            ],
            'operational_flow' => [
                $this->plan('recheck_system', 'Volver a revisar VITI', 'Confirmar si el problema operativo continúa antes de realizar cambios.', true, false),
                $this->plan('prepare_attention', 'Preparar atención administrativa', 'Agrupar el trabajo pendiente para revisión humana.', true, false),
            ],
            default => [
                $this->plan('recheck_system', 'Comprobar nuevamente', 'Verificar si la condición detectada sigue presente.', true, false),
            ],
        };

        return array_values(array_filter($plans));
    }

    public function canExecute(string $action): bool
    {
        return in_array($action, ['recheck_system', 'refresh_agr_state'], true);
    }

    private function plan(string $action, string $label, string $description, bool $requiresConfirmation, bool $destructive): array
    {
        return [
            'action' => $action,
            'label' => $label,
            'description' => $description,
            'requires_confirmation' => $requiresConfirmation,
            'destructive' => $destructive,
            'status' => 'ready',
        ];
    }
}
