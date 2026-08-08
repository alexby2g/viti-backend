<?php

namespace App\Observers;

use App\Models\{Empresa,PlanViti,Usuario};

class EmpresaObserver
{
    public function created(Empresa $empresa): void
    {
        if (!$empresa->plan_viti_id) {
            $planId = PlanViti::where('codigo','personalizado')->value('id');
            if ($planId) $empresa->forceFill(['plan_viti_id'=>$planId])->saveQuietly();
        }

        if ($empresa->cliente_id) {
            $users = Usuario::where('rol','cliente')->where('cliente_id',$empresa->cliente_id)->where('estado','activo')->pluck('id');
            foreach ($users as $userId) {
                $empresa->usuarios()->syncWithoutDetaching([$userId=>['rol_negocio'=>'propietario','activo'=>true]]);
            }
        }
    }
}
