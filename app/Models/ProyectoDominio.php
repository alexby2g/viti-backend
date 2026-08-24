<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProyectoDominio extends Model
{
    protected $table = 'proyecto_dominios';

    protected $fillable = [
        'proyecto_id', 'ambiente_id', 'dominio', 'tipo', 'estado',
        'verificado_at', 'notas',
    ];

    protected $casts = ['verificado_at' => 'datetime'];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class);
    }

    public function ambiente()
    {
        return $this->belongsTo(ProyectoAmbiente::class, 'ambiente_id');
    }
}
