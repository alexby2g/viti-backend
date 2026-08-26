<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudAccesoViti extends Model
{
    protected $table = 'solicitudes_acceso_viti';

    protected $fillable = [
        'nombre','telefono','whatsapp','negocio','actividad','plan_codigo','modalidad','mensaje',
        'estado','revisado_por','revisado_at','notas','invitacion_id',
    ];

    protected $casts = ['revisado_at' => 'datetime'];

    public function revisor() { return $this->belongsTo(Usuario::class, 'revisado_por'); }
    public function plan() { return $this->belongsTo(PlanViti::class, 'plan_codigo', 'codigo'); }
    public function invitacion() { return $this->belongsTo(InvitacionCliente::class, 'invitacion_id'); }
}
