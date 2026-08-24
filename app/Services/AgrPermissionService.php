<?php

namespace App\Services;

class AgrPermissionService
{
    public const LEVEL_OBSERVER = 0;
    public const LEVEL_ASSISTANT = 1;
    public const LEVEL_OPERATOR = 2;
    public const LEVEL_AUTOPILOT = 3;

    public function level(): int
    {
        $configured = (int) env('AGR_PERMISSION_LEVEL', self::LEVEL_ASSISTANT);
        return max(self::LEVEL_OBSERVER, min(self::LEVEL_AUTOPILOT, $configured));
    }

    public function policy(): array
    {
        $level = $this->level();

        return [
            'level' => $level,
            'name' => match ($level) {
                self::LEVEL_OBSERVER => 'Observador',
                self::LEVEL_ASSISTANT => 'Asistente',
                self::LEVEL_OPERATOR => 'Operador',
                self::LEVEL_AUTOPILOT => 'Autopilot',
            },
            'allowed' => [
                'read_data' => true,
                'search_records' => true,
                'navigate_modules' => $level >= self::LEVEL_ASSISTANT,
                'prepare_actions' => $level >= self::LEVEL_ASSISTANT,
                'write_safe_data' => $level >= self::LEVEL_OPERATOR,
                'write_sensitive_data' => false,
                'delete_data' => false,
                'charge_customer' => false,
                'change_critical_business_data' => false,
            ],
            'requires_confirmation' => [
                'create_client' => $level < self::LEVEL_OPERATOR,
                'create_company' => $level < self::LEVEL_OPERATOR,
                'create_request' => $level < self::LEVEL_OPERATOR,
                'create_project' => true,
                'resolve_support' => true,
                'change_status' => true,
            ],
        ];
    }

    public function can(string $action): bool
    {
        return (bool) ($this->policy()['allowed'][$action] ?? false);
    }

    public function requiresConfirmation(string $action): bool
    {
        return (bool) ($this->policy()['requires_confirmation'][$action] ?? true);
    }
}
