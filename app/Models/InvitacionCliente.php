<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvitacionCliente extends Model
{
    protected $table = 'invitaciones_clientes';

    protected $fillable = [
        'token','creada_por','cliente_id','solicitud_id','estado','expira_at','usada_at',
    ];

    protected $casts = [
        'expira_at' => 'datetime',
        'usada_at' => 'datetime',
    ];

    public function creador() { return $this->belongsTo(Usuario::class, 'creada_por'); }
    public function cliente() { return $this->belongsTo(Cliente::class); }
    public function solicitud() { return $this->belongsTo(SolicitudSistema::class); }
}
