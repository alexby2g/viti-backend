<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeluqueriaPago extends Model
{
    protected $table = 'peluqueria_pagos';
    protected $fillable = ['empresa_id','atencion_id','metodo','monto','referencia','pagado_at'];
    protected $casts = ['monto'=>'decimal:2','pagado_at'=>'datetime'];

    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function atencion(){ return $this->belongsTo(PeluqueriaAtencion::class,'atencion_id'); }
}
