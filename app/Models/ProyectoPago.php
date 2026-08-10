<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProyectoPago extends Model
{
    protected $table = 'proyecto_pagos';
    protected $fillable = [
        'proyecto_id','empresa_id','pagador_usuario_id','tipo','monto','metodo','fecha_pago','referencia',
        'comprobante_path','comprobante_nombre','comprobante_mime','observaciones','registrado_por',
        'estado_revision','origen','enviado_at','revisado_at','revisado_por','motivo_revision',
    ];
    protected $casts = [
        'monto'=>'decimal:2',
        'fecha_pago'=>'date:Y-m-d',
        'enviado_at'=>'datetime',
        'revisado_at'=>'datetime',
    ];

    public function proyecto(){ return $this->belongsTo(Proyecto::class); }
    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function pagador(){ return $this->belongsTo(Usuario::class,'pagador_usuario_id'); }
    public function revisor(){ return $this->belongsTo(Usuario::class,'revisado_por'); }
}
