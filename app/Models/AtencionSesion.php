<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AtencionSesion extends Model
{
    protected $table = 'atencion_sesiones';

    protected $fillable = [
        'conversacion_id',
        'cliente_id',
        'solicitada_por_usuario_id',
        'aprobada_por_usuario_id',
        'modalidad',
        'estado',
        'motivo',
        'nota_admin',
        'programada_para',
        'habilitada_desde',
        'habilitada_hasta',
        'aprobada_at',
    ];

    protected $casts = [
        'programada_para' => 'datetime',
        'habilitada_desde' => 'datetime',
        'habilitada_hasta' => 'datetime',
        'aprobada_at' => 'datetime',
    ];

    public function conversacion(){ return $this->belongsTo(Conversacion::class); }
    public function cliente(){ return $this->belongsTo(Cliente::class); }
    public function solicitante(){ return $this->belongsTo(Usuario::class, 'solicitada_por_usuario_id'); }
    public function aprobador(){ return $this->belongsTo(Usuario::class, 'aprobada_por_usuario_id'); }

    public function scopeHabilitadaAhora($query)
    {
        return $query
            ->where('estado', 'aprobada')
            ->whereNotNull('habilitada_desde')
            ->whereNotNull('habilitada_hasta')
            ->where('habilitada_desde', '<=', now())
            ->where('habilitada_hasta', '>=', now());
    }

    public function estaHabilitadaAhora(): bool
    {
        return $this->estado === 'aprobada'
            && $this->habilitada_desde
            && $this->habilitada_hasta
            && now()->between($this->habilitada_desde, $this->habilitada_hasta);
    }
}
