<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProyectoTarea extends Model
{
    protected $table = 'proyecto_tareas';

    protected $fillable = [
        'proyecto_id', 'responsable_id', 'creado_por', 'titulo', 'descripcion',
        'criterios_aceptacion', 'area', 'estado', 'prioridad', 'fecha_limite',
        'completada_at',
    ];

    protected $casts = [
        'fecha_limite' => 'date:Y-m-d',
        'completada_at' => 'datetime',
    ];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class);
    }

    public function responsable()
    {
        return $this->belongsTo(Usuario::class, 'responsable_id');
    }

    public function creador()
    {
        return $this->belongsTo(Usuario::class, 'creado_por');
    }
}
