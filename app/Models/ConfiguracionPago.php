<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionPago extends Model
{
    protected $table = 'configuraciones_pago';
    protected $fillable = ['nombre','banco','titular','moneda','qr_path','activo','observaciones'];
    protected $casts = ['activo'=>'boolean'];
}
