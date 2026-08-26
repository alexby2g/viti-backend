<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvitacionCliente extends Model
{
    protected $table='invitaciones_clientes';

    protected $fillable=[
        'token','codigo','creada_por','cliente_id','solicitud_id','correo_destino','estado','expira_at','enviada_at','ultimo_envio_at','intentos_envio','usada_at','revocada_at','error_envio',
    ];

    protected $casts=[
        'expira_at'=>'datetime','enviada_at'=>'datetime','ultimo_envio_at'=>'datetime','usada_at'=>'datetime','revocada_at'=>'datetime','intentos_envio'=>'integer',
    ];

    public function creador(){ return $this->belongsTo(Usuario::class,'creada_por'); }
    public function cliente(){ return $this->belongsTo(Cliente::class); }
    public function solicitud(){ return $this->belongsTo(SolicitudSistema::class); }
    public function solicitudAccesoViti(){ return $this->hasOne(SolicitudAccesoViti::class,'invitacion_id'); }
}
