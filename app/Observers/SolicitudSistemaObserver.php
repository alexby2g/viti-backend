<?php

namespace App\Observers;

use App\Models\{AlertaSaas,SolicitudSistema,Usuario};
use App\Support\FirebasePush;

class SolicitudSistemaObserver
{
    public function updated(SolicitudSistema $solicitud): void
    {
        if (!$solicitud->wasChanged('estado') || $solicitud->estado !== 'en_revision') return;

        $solicitud->loadMissing(['cliente:id,nombre','empresa:id,nombre_comercial']);
        $admins = Usuario::query()
            ->where('estado','activo')
            ->whereIn('rol',['superadmin','administrador'])
            ->get(['id']);

        if ($admins->isEmpty()) return;

        $title = 'Nueva solicitud enviada';
        $business = $solicitud->empresa?->nombre_comercial ?: 'Empresa sin nombre';
        $client = $solicitud->cliente?->nombre ?: 'Cliente';
        $message = $solicitud->codigo.' · '.$business.' · '.$client;
        $path = '/solicitudes/'.$solicitud->id;

        foreach ($admins as $admin) {
            AlertaSaas::firstOrCreate(
                ['usuario_id'=>$admin->id,'clave'=>'solicitud_enviada_'.$solicitud->id],
                ['empresa_id'=>$solicitud->empresa_id,'tipo'=>'solicitud','titulo'=>$title,'mensaje'=>$message,'ruta'=>$path]
            );
        }

        FirebasePush::sendToUsers($admins->pluck('id')->all(), $title, $message, [
            'type'=>'solicitud',
            'solicitud_id'=>$solicitud->id,
            'path'=>$path,
        ]);
    }
}
