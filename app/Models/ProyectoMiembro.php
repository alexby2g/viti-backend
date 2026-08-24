<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProyectoMiembro extends Model
{
    protected $table = 'proyecto_miembros';

    protected $fillable = [
        'proyecto_id', 'usuario_id', 'rol', 'permisos', 'activo',
    ];

    protected $casts = ['permisos' => 'array', 'activo' => 'boolean'];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }
}
