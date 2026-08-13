<?php

namespace App\Services;

use App\Models\Empresa;

class FeatureGateService
{
    public const MODULES = [
        'inicio','agenda','ordenes','clientes','equipos','tecnicos',
        'inventario','pagos','garantias','historial','buzon',
    ];

    public function modules(Empresa $empresa): ?array
    {
        $modules = $empresa->planViti?->modulos;
        if ($modules === null || $modules === []) return null;

        return array_values(array_unique(array_intersect(
            self::MODULES,
            array_values(array_filter(array_map(
                fn ($module) => trim((string) $module),
                (array) $modules
            )))
        )));
    }

    public function hasModule(Empresa $empresa, string $module): bool
    {
        if (!in_array($module, self::MODULES, true)) return false;
        $modules = $this->modules($empresa);
        return $modules === null || in_array($module, $modules, true);
    }

    public function assertModule(Empresa $empresa, string $module): void
    {
        abort_unless(
            $this->hasModule($empresa, $module),
            403,
            'Este módulo no forma parte del plan VITI asignado a tu negocio.'
        );
    }

    public function assertUserLimit(Empresa $empresa): void
    {
        $limit = $empresa->planViti?->max_usuarios;
        if ($limit === null) return;
        $current = $empresa->usuarios()->wherePivot('activo', true)->count();
        abort_if($current >= $limit, 422, 'Este negocio alcanzó el límite de usuarios de su plan VITI.');
    }

    public function assertAppLimit(Empresa $empresa): void
    {
        $limit = $empresa->planViti?->max_aplicaciones;
        if ($limit === null) return;
        $current = $empresa->aplicaciones()->whereNotIn('estado', ['retirado'])->count();
        abort_if($current >= $limit, 422, 'Este negocio alcanzó el límite de aplicaciones de su plan VITI.');
    }

    public function snapshot(Empresa $empresa): array
    {
        $empresa->loadMissing('planViti');
        $plan = $empresa->planViti;
        $users = $empresa->usuarios()->wherePivot('activo', true)->count();
        $apps = $empresa->aplicaciones()->whereNotIn('estado', ['retirado'])->count();
        $modules = $this->modules($empresa);

        return [
            'plan' => $plan ? [
                'id' => $plan->id,
                'codigo' => $plan->codigo,
                'nombre' => $plan->nombre,
            ] : null,
            'modulos' => $modules,
            'catalogo_modulos' => self::MODULES,
            'usuarios' => $this->usage($users, $plan?->max_usuarios),
            'aplicaciones' => $this->usage($apps, $plan?->max_aplicaciones),
        ];
    }

    private function usage(int $used, ?int $limit): array
    {
        return [
            'usados' => $used,
            'maximo' => $limit,
            'restantes' => $limit === null ? null : max(0, $limit - $used),
            'sin_limite' => $limit === null,
            'alcanzado' => $limit !== null && $used >= $limit,
        ];
    }
}
