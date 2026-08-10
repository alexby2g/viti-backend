<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectrofrioEquipo extends Model
{
    protected $table = 'electrofrio_equipos';
    protected $fillable = ['empresa_id','cliente_id','tipo','marca','modelo','serie','capacidad','ubicacion','observaciones','activo'];
    protected $casts = ['activo'=>'boolean'];
    public function cliente(){ return $this->belongsTo(ElectrofrioCliente::class, 'cliente_id'); }
}
