<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProyectoRepositorio extends Model
{
    protected $table = 'proyecto_repositorios';

    protected $fillable = [
        'proyecto_id', 'tipo', 'proveedor', 'nombre', 'url',
        'rama_principal', 'privado', 'descripcion',
    ];

    protected $casts = ['privado' => 'boolean'];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class);
    }
}
