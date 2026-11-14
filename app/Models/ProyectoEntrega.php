<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProyectoEntrega extends Model
{
    protected $table = 'proyecto_entregas';

    protected $fillable = [
        'proyecto_id', 'titulo', 'instrucciones', 'archivo_ids',
        'estado', 'publicado_por', 'publicado_at',
    ];

    protected $casts = [
        'archivo_ids' => 'array',
        'publicado_at' => 'datetime',
    ];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class);
    }

    public function publicador()
    {
        return $this->belongsTo(Usuario::class, 'publicado_por');
    }
}
