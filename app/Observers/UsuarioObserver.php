<?php

namespace App\Observers;

use App\Models\{AlertaSaas,Empresa,Usuario};
use App\Support\FirebasePush;

class UsuarioObserver
{
    public function created(Usuario $usuario): void
    {
        if ($usuario->rol !== 'cliente' || !$usuario->cliente_id) return;

        $businesses = Empresa::where('cliente_id',$usuario->cliente_id)->get();
        foreach ($businesses as $empresa) {
            $empresa->usuarios()->syncWithoutDetaching([$usuario->id=>['rol_negocio'=>'propietario','activo'=>true]]);
        }

        $admins = Usuario::query()
            ->where('estado','activo')
            ->whereIn('rol',['superadmin','administrador'])
            ->whereKeyNot($usuario->id)
            ->get(['id']);

        if ($admins->isEmpty()) return;

        $title = 'Nuevo usuario registrado';
        $message = trim($usuario->nombre ?: 'Cliente').' · @'.$usuario->usuario;
        $path = '/clientes';

        foreach ($admins as $admin) {
            AlertaSaas::firstOrCreate(
                ['usuario_id'=>$admin->id,'clave'=>'usuario_registrado_'.$usuario->id],
                ['tipo'=>'registro','titulo'=>$title,'mensaje'=>$message,'ruta'=>$path]
            );
        }

        FirebasePush::sendToUsers($admins->pluck('id')->all(), $title, $message, [
            'type'=>'registro',
            'usuario_id'=>$usuario->id,
            'cliente_id'=>$usuario->cliente_id,
            'path'=>$path,
        ]);
    }
}
