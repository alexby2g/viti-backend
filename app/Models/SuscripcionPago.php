<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuscripcionPago extends Model
{
    protected $table = 'suscripcion_pagos';
    protected $fillable = ['suscripcion_id','empresa_id','pagador_usuario_id','monto','metodo','fecha_pago','referencia','comprobante_path','observaciones','registrado_por'];
    protected $casts = ['monto'=>'decimal:2','fecha_pago'=>'date:Y-m-d'];

    public function suscripcion(){ return $this->belongsTo(Suscripcion::class); }
    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function pagador(){ return $this->belongsTo(Usuario::class,'pagador_usuario_id'); }
}
