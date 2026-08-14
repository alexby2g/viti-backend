<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectrofrioFichaTecnica extends Model
{
    protected $table = 'electrofrio_fichas_tecnicas';

    protected $fillable = [
        'empresa_id',
        'equipo_id',
        'gas_refrigerante',
        'voltaje',
        'amperaje_nominal',
        'presion_succion_psi',
        'presion_descarga_psi',
        'observaciones_tecnicas',
        'actualizado_por',
    ];

    protected $casts = [
        'amperaje_nominal' => 'decimal:2',
        'presion_succion_psi' => 'decimal:2',
        'presion_descarga_psi' => 'decimal:2',
    ];

    public function equipo()
    {
        return $this->belongsTo(ElectrofrioEquipo::class, 'equipo_id');
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function actualizadoPor()
    {
        return $this->belongsTo(Usuario::class, 'actualizado_por');
    }
}
