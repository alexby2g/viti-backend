<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProyectoAmbiente extends Model
{
    protected $table = 'proyecto_ambientes';

    protected $fillable = [
        'proyecto_id', 'tipo', 'nombre', 'frontend_url', 'backend_url',
        'proveedor_frontend', 'proveedor_backend', 'base_datos_referencia',
        'estado', 'notas',
    ];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class);
    }

    public function dominios()
    {
        return $this->hasMany(ProyectoDominio::class, 'ambiente_id');
    }
}
