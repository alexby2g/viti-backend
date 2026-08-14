<?php

namespace App\Services;

use App\Models\{Empresa,Usuario};
use Illuminate\Http\Request;

class TenantContext
{
    private const EMPLOYEE_DEFAULT_MODULES = ['inicio','agenda','ordenes'];

    public function __construct(private FeatureGateService $features) {}

    public function resolve(Request $request): Empresa
    {
        $user = $request->user();
        abort_unless($user,401,'Debes iniciar sesión.');

        $rawHeaderBusinessId = trim((string)$request->header('X-VITI-Empresa',''));
        $rawPayloadBusinessId = $request->input('empresa_id');

        if ($rawHeaderBusinessId !== '') {
            abort_unless(
                ctype_digit($rawHeaderBusinessId) && (int)$rawHeaderBusinessId > 0,
                422,
                'Contexto de empresa inválido.'
            );
        }

        if ($rawPayloadBusinessId !== null && $rawPayloadBusinessId !== '') {
            $rawPayloadBusinessId = trim((string)$rawPayloadBusinessId);
            abort_unless(
                ctype_digit($rawPayloadBusinessId) && (int)$rawPayloadBusinessId > 0,
                422,
                'Contexto de empresa inválido.'
            );
        }

        $headerBusinessId = $rawHeaderBusinessId === '' ? 0 : (int)$rawHeaderBusinessId;
        $payloadBusinessId = $rawPayloadBusinessId === null || $rawPayloadBusinessId === '' ? 0 : (int)$rawPayloadBusinessId;
        abort_if(
            $headerBusinessId && $payloadBusinessId && $headerBusinessId !== $payloadBusinessId,
            422,
            'Contexto de empresa inconsistente.'
        );
        $requested = $headerBusinessId ?: $payloadBusinessId;

        if ($user->isPlatformAdmin()) {
            abort_unless($requested,422,'Selecciona una empresa.');
            $business = Empresa::query()->with('planViti')->findOrFail($requested);
            $request->attributes->set('viti_empresa_id',$business->id);
            return $business;
        }

        $query = $user->negocios()->wherePivot('activo',true)->with('planViti');
        if ($requested) {
            $business = $query->where('empresas.id',$requested)->first();
        } else {
            // Con una sola empresa podemos resolver el contexto de forma segura.
            // Con varias, elegir silenciosamente la primera puede escribir datos en el
            // negocio equivocado si el frontend pierde el encabezado de empresa activa.
            $businesses = $query->orderBy('empresas.id')->limit(2)->get();
            abort_if(
                $businesses->count() > 1,
                422,
                'Selecciona el negocio activo antes de continuar.'
            );
            $business = $businesses->first();
        }

        abort_unless($business,403,'No tienes acceso a este negocio.');
        $request->attributes->set('viti_empresa_id',$business->id);
        $request->attributes->set('viti_rol_negocio',$business->pivot?->rol_negocio);
        $request->attributes->set('viti_permisos_negocio',$this->pivotPermissions($business->pivot?->permisos));
        return $business;
    }

    public function role(Usuario $user, Empresa $empresa): ?string
    {
        if ($user->isSuperAdmin()) return 'superadmin';
        if ($user->rol === 'administrador') return 'administrador_viti';
        return $user->negocios()->where('empresas.id',$empresa->id)->wherePivot('activo',true)->first()?->pivot?->rol_negocio;
    }

    public function canManage(Usuario $user, Empresa $empresa): bool
    {
        return in_array($this->role($user,$empresa), ['superadmin','administrador_viti','propietario','administrador'], true);
    }

    public function assertCanManage(Usuario $user, Empresa $empresa): void
    {
        abort_unless($this->canManage($user,$empresa),403,'No tienes permisos administrativos en este negocio.');
    }

    public function permissions(Usuario $user, Empresa $empresa): ?array
    {
        if ($user->isPlatformAdmin()) return null;
        $membership = $user->negocios()->where('empresas.id',$empresa->id)->wherePivot('activo',true)->first();
        if (!$membership) return [];
        if (in_array($membership->pivot?->rol_negocio, ['propietario','administrador'], true)) return null;
        $permissions = $this->pivotPermissions($membership->pivot?->permisos);
        return $permissions === null ? self::EMPLOYEE_DEFAULT_MODULES : $permissions;
    }

    public function canUse(Usuario $user, Empresa $empresa, string $module): bool
    {
        if (!$this->features->hasModule($empresa,$module)) return false;
        $permissions = $this->permissions($user,$empresa);
        return $permissions === null || in_array($module,$permissions,true);
    }

    public function assertCanUse(Usuario $user, Empresa $empresa, string $module): void
    {
        abort_unless($this->canUse($user,$empresa,$module),403,'Tu rol o plan no tiene permiso para usar este módulo de la aplicación VITI.');
    }

    public function assertUserLimit(Empresa $empresa): void
    {
        $this->features->assertUserLimit($empresa);
    }

    public function assertAppLimit(Empresa $empresa): void
    {
        $this->features->assertAppLimit($empresa);
    }

    public function modules(Empresa $empresa): ?array
    {
        return $this->features->modules($empresa);
    }

    public function hasModule(Empresa $empresa, string $module): bool
    {
        return $this->features->hasModule($empresa,$module);
    }

    public function assertModule(Empresa $empresa, string $module): void
    {
        $this->features->assertModule($empresa,$module);
    }

    public function effectiveModules(Usuario $user, Empresa $empresa): ?array
    {
        $plan = $this->modules($empresa);
        $permissions = $this->permissions($user,$empresa);
        if ($plan === null) return $permissions;
        if ($permissions === null) return $plan;
        return array_values(array_intersect($plan,$permissions));
    }

    public function featureSnapshot(Usuario $user, Empresa $empresa): array
    {
        return [
            ...$this->features->snapshot($empresa),
            'rol'=>$this->role($user,$empresa),
            'puede_administrar'=>$this->canManage($user,$empresa),
            'modulos_efectivos'=>$this->effectiveModules($user,$empresa),
        ];
    }

    private function pivotPermissions(mixed $value): ?array
    {
        if ($value === null || $value === '') return null;
        if (is_string($value)) $value = json_decode($value,true);
        if (!is_array($value)) return [];
        $clean = array_values(array_unique(array_filter(array_map(fn ($permission) => trim((string)$permission),$value))));
        return array_values(array_intersect(FeatureGateService::MODULES,$clean));
    }
}
