<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Llamada extends Model
{
    protected $table = 'llamadas';

    protected $fillable = [
        'conversacion_id', 'cliente_id', 'electrofrio_cliente_id', 'atencion_sesion_id', 'iniciada_por_usuario_id', 'receptor_usuario_id',
        'tipo', 'estado', 'offer_sdp', 'answer_sdp', 'contestada_at', 'finalizada_at',
    ];

    protected $casts = [
        'contestada_at' => 'datetime',
        'finalizada_at' => 'datetime',
    ];

    public function conversacion() { return $this->belongsTo(Conversacion::class); }
    public function cliente() { return $this->belongsTo(Cliente::class); }
    public function electrofrioCliente() { return $this->belongsTo(ElectrofrioCliente::class, 'electrofrio_cliente_id'); }
    public function sesionAtencion() { return $this->belongsTo(AtencionSesion::class, 'atencion_sesion_id'); }
    public function iniciador() { return $this->belongsTo(Usuario::class, 'iniciada_por_usuario_id'); }
    public function receptor() { return $this->belongsTo(Usuario::class, 'receptor_usuario_id'); }
    public function senales() { return $this->hasMany(LlamadaSenal::class); }
}
