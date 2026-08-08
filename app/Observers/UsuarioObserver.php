<?php

namespace App\Observers;

use App\Models\{Empresa,Usuario};

class UsuarioObserver
{
    public function created(Usuario $usuario): void
    {
        if ($usuario->rol !== 'cliente' || !$usuario->cliente_id) return;
        $businesses = Empresa::where('cliente_id',$usuario->cliente_id)->get();
        foreach ($businesses as $empresa) {
            $empresa->usuarios()->syncWithoutDetaching([$usuario->id=>['rol_negocio'=>'propietario','activo'=>true]]);
        }
    }
}
