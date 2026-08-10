<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Suscripcion extends Model
{
    protected $table = 'suscripciones';
    protected $fillable = ['aplicacion_id','empresa_id','plan','monto','frecuencia','moneda','fecha_inicio','prueba_hasta','primer_cobro_monto','primer_cobro_desde','primer_cobro_hasta','primer_cobro_pagado','fecha_vencimiento','dias_gracia','estado'];
    protected $casts = [
        'monto'=>'decimal:2',
        'fecha_inicio'=>'date:Y-m-d',
        'prueba_hasta'=>'date:Y-m-d',
        'primer_cobro_monto'=>'decimal:2',
        'primer_cobro_desde'=>'date:Y-m-d',
        'primer_cobro_hasta'=>'date:Y-m-d',
        'primer_cobro_pagado'=>'boolean',
        'fecha_vencimiento'=>'date:Y-m-d',
        'dias_gracia'=>'integer',
    ];

    public function aplicacion(){ return $this->belongsTo(Aplicacion::class); }
    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function pagos(){ return $this->hasMany(SuscripcionPago::class)->latest('fecha_pago'); }
}
