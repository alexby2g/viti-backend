<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectrofrioConfiguracion extends Model
{
    protected $table = 'electrofrio_configuraciones';

    protected $fillable = [
        'empresa_id',
        'nombre_sistema',
        'nombre_corto',
        'logo_url',
        'telefono',
        'correo',
        'direccion',
        'color_primario',
        'color_secundario',
        'moneda',
        'garantia_dias_default',
        'tipos_servicio',
        'tipos_equipo',
        'metodos_pago',
        'actualizado_por',
    ];

    protected $casts = [
        'garantia_dias_default' => 'integer',
        'tipos_servicio' => 'array',
        'tipos_equipo' => 'array',
        'metodos_pago' => 'array',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
