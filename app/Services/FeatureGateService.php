<?php

namespace App\Services;

use App\Models\{Aplicacion,Empresa};

class FeatureGateService
{
    public const MODULES = ['inicio','agenda','ordenes','clientes','equipos','tecnicos','inventario','pagos','garantias','historial','buzon'];

    public function modules(Empresa $empresa): ?array
    {
        $application=$this->activeApplication($empresa);
        return $application ? $this->modulesForApp($application) : $this->planModules($empresa);
    }

    public function modulesForApp(Aplicacion $application): ?array
    {
        $application->loadMissing('empresa.planViti');
        $config=(array)($application->configuracion??[]);
        $inherit=!array_key_exists('heredar_modulos_plan',$config)||$config['heredar_modulos_plan']!==false;
        $planModules=$this->planModules($application->empresa);
        if($inherit) return $planModules;

        $selected=$this->normalize($config['modulos']??[]);
        if($planModules===null) return $selected;
        return array_values(array_intersect($selected,$planModules));
    }

    public function hasModule(Empresa $empresa,string $module): bool
    {
        if(!in_array($module,self::MODULES,true))return false;
        $modules=$this->modules($empresa);
        return $modules===null||in_array($module,$modules,true);
    }

    public function hasModuleForApp(Aplicacion $application,string $module): bool
    {
        if(!in_array($module,self::MODULES,true))return false;
        $modules=$this->modulesForApp($application);
        return $modules===null||in_array($module,$modules,true);
    }

    public function assertModule(Empresa $empresa,string $module): void
    {
        abort_unless($this->hasModule($empresa,$module),403,'Este módulo no está habilitado para la aplicación o plan actual.');
    }

    public function assertAppModule(Aplicacion $application,string $module): void
    {
        abort_unless($this->hasModuleForApp($application,$module),403,'Este módulo no está habilitado para esta aplicación.');
    }

    public function assertUserLimit(Empresa $empresa): void
    {
        $limit=$empresa->planViti?->max_usuarios;if($limit===null)return;
        $current=$empresa->usuarios()->wherePivot('activo',true)->count();
        abort_if($current>=$limit,422,'Este negocio alcanzó el límite de usuarios de su plan VITI.');
    }

    public function assertAppLimit(Empresa $empresa): void
    {
        $limit=$empresa->planViti?->max_aplicaciones;if($limit===null)return;
        $current=$empresa->aplicaciones()->whereNotIn('estado',['retirado'])->count();
        abort_if($current>=$limit,422,'Este negocio alcanzó el límite de aplicaciones de su plan VITI.');
    }

    public function snapshot(Empresa $empresa): array
    {
        $empresa->loadMissing('planViti');$plan=$empresa->planViti;
        $users=$empresa->usuarios()->wherePivot('activo',true)->count();$apps=$empresa->aplicaciones()->whereNotIn('estado',['retirado'])->count();$modules=$this->modules($empresa);
        return ['plan'=>$plan?['id'=>$plan->id,'codigo'=>$plan->codigo,'nombre'=>$plan->nombre]:null,'modulos'=>$modules,'catalogo_modulos'=>self::MODULES,'usuarios'=>$this->usage($users,$plan?->max_usuarios),'aplicaciones'=>$this->usage($apps,$plan?->max_aplicaciones)];
    }

    public function moduleCatalog(): array
    {
        return [
            ['key'=>'inicio','label'=>'Inicio','icon'=>'dashboard'],['key'=>'agenda','label'=>'Agenda','icon'=>'calendar_month'],['key'=>'ordenes','label'=>'Órdenes','icon'=>'assignment'],['key'=>'clientes','label'=>'Clientes','icon'=>'groups'],['key'=>'equipos','label'=>'Equipos','icon'=>'devices_other'],['key'=>'tecnicos','label'=>'Técnicos','icon'=>'engineering'],['key'=>'inventario','label'=>'Inventario','icon'=>'inventory_2'],['key'=>'pagos','label'=>'Pagos','icon'=>'payments'],['key'=>'garantias','label'=>'Garantías','icon'=>'verified'],['key'=>'historial','label'=>'Historial','icon'=>'history'],['key'=>'buzon','label'=>'Buzón','icon'=>'forum'],
        ];
    }

    private function planModules(Empresa $empresa): ?array
    {
        $modules=$empresa->planViti?->modulos;
        if($modules===null||$modules===[])return null;
        return $this->normalize((array)$modules);
    }

    private function activeApplication(Empresa $empresa): ?Aplicacion
    {
        $path=request()->path();$catalogKey=null;
        if(str_contains($path,'servicio-tecnico'))$catalogKey='servicio-tecnico';
        elseif(str_contains($path,'electrofrio')||str_contains($path,'aires'))$catalogKey='electrofrio';
        elseif(str_contains($path,'peluqueria'))$catalogKey='peluqueria';
        if(!$catalogKey)return null;
        return Aplicacion::query()->where('empresa_id',$empresa->id)->whereHas('catalogo',fn($q)=>$q->where('clave',$catalogKey))->whereNotIn('estado',['retirado'])->whereNull('deleted_at')->latest('id')->first();
    }

    private function normalize(array $modules): array
    {
        return array_values(array_unique(array_intersect(self::MODULES,array_values(array_filter(array_map(fn($module)=>trim((string)$module),$modules))))));
    }

    private function usage(int $used,?int $limit): array
    {
        return ['usados'=>$used,'maximo'=>$limit,'restantes'=>$limit===null?null:max(0,$limit-$used),'sin_limite'=>$limit===null,'alcanzado'=>$limit!==null&&$used>=$limit];
    }
}
