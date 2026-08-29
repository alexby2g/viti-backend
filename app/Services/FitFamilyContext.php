<?php

namespace App\Services;

use App\Models\Empresa;
use Illuminate\Http\Request;

class FitFamilyContext
{
    public function resolve(Request $request): Empresa
    {
        $configuredId = config('fitfamily.empresa_id');
        $requestedId = $request->header('X-FitFamily-Empresa');
        $user = $request->user();

        // Public Store requests must resolve to the deployment's configured
        // tenant. Never let an unauthenticated caller select another company.
        if ($user && $requestedId) {
            if ($user->isSuperAdmin()) {
                $configuredId = $requestedId;
            } elseif ($user->negocios()->whereKey($requestedId)->wherePivot('activo', true)->exists()) {
                $configuredId = $requestedId;
            }
        }

        abort_unless($configuredId, 503, 'FitFamily todavía no tiene una empresa configurada.');

        $empresa = Empresa::query()->find($configuredId);
        abort_unless($empresa, 404, 'La empresa de FitFamily no existe.');

        abort_unless(
            $empresa->aplicaciones()->whereHas(
                'catalogo',
                fn ($query) => $query->where('clave', config('fitfamily.catalog_key'))
            )->exists(),
            404,
            'La aplicación FitFamily no está asignada a esta empresa.'
        );

        return $empresa;
    }

    public function assertAdmin(Request $request, Empresa $empresa): void
    {
        $user = $request->user();

        abort_unless($user, 401, 'Unauthenticated.');
        abort_unless($user->isPlatformAdmin(), 403, 'Solo un administrador puede realizar esta acción.');

        if (!$user->isSuperAdmin()) {
            abort_unless(
                $user->negocios()->whereKey($empresa->id)->wherePivot('activo', true)->exists(),
                403,
                'No tienes acceso administrativo a esta empresa.'
            );
        }
    }
}
