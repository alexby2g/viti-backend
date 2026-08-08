<?php

namespace App\Services;

use App\Models\{Empresa,Usuario};
use Illuminate\Http\Request;

class TenantContext
{
    public function resolve(Request $request): Empresa
    {
        $user = $request->user();
        abort_unless($user,401,'Debes iniciar sesión.');

        $requested = (int)($request->header('X-VITI-Empresa') ?: $request->input('empresa_id',0));

        if ($user->isSuperAdmin()) {
            abort_unless($requested,422,'Selecciona una empresa.');
            $business = Empresa::query()->with('planViti')->findOrFail($requested);
            $request->attributes->set('viti_empresa_id',$business->id);
            return $business;
        }

        $query = $user->negocios()->wherePivot('activo',true);
        if ($requested) $query->where('empresas.id',$requested);
        $business = $query->with('planViti')->orderBy('empresas.id')->first();

        abort_unless($business,403,'No tienes acceso a este negocio.');
        $request->attributes->set('viti_empresa_id',$business->id);
        $request->attributes->set('viti_rol_negocio',$business->pivot?->rol_negocio);
        return $business;
    }

    public function role(Usuario $user, Empresa $empresa): ?string
    {
        if ($user->isSuperAdmin()) return 'superadmin';
        return $user->negocios()->where('empresas.id',$empresa->id)->wherePivot('activo',true)->first()?->pivot?->rol_negocio;
    }

    public function canManage(Usuario $user, Empresa $empresa): bool
    {
        return in_array($this->role($user,$empresa), ['superadmin','propietario','administrador'], true);
    }

    public function assertCanManage(Usuario $user, Empresa $empresa): void
    {
        abort_unless($this->canManage($user,$empresa),403,'No tienes permisos administrativos en este negocio.');
    }

    public function assertUserLimit(Empresa $empresa): void
    {
        $limit = $empresa->planViti?->max_usuarios;
        if ($limit === null) return;
        $current = $empresa->usuarios()->wherePivot('activo',true)->count();
        abort_if($current >= $limit,422,'Este negocio alcanzó el límite de usuarios de su plan VITI.');
    }

    public function assertAppLimit(Empresa $empresa): void
    {
        $limit = $empresa->planViti?->max_aplicaciones;
        if ($limit === null) return;
        $current = $empresa->aplicaciones()->whereNotIn('estado',['retirado'])->count();
        abort_if($current >= $limit,422,'Este negocio alcanzó el límite de aplicaciones de su plan VITI.');
    }
}
