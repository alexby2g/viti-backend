<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProyectoPago extends Model
{
    protected $table = 'proyecto_pagos';
    protected $fillable = ['proyecto_id','empresa_id','pagador_usuario_id','tipo','monto','metodo','fecha_pago','referencia','comprobante_path','observaciones','registrado_por'];
    protected $casts = ['monto'=>'decimal:2','fecha_pago'=>'date:Y-m-d'];

    public function proyecto(){ return $this->belongsTo(Proyecto::class); }
    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function pagador(){ return $this->belongsTo(Usuario::class,'pagador_usuario_id'); }
}
