<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Suscripcion extends Model
{
    protected $table = 'suscripciones';
    protected $fillable = ['aplicacion_id','empresa_id','plan','monto','frecuencia','moneda','fecha_inicio','fecha_vencimiento','dias_gracia','estado'];
    protected $casts = ['monto'=>'decimal:2','fecha_inicio'=>'date:Y-m-d','fecha_vencimiento'=>'date:Y-m-d','dias_gracia'=>'integer'];

    public function aplicacion(){ return $this->belongsTo(Aplicacion::class); }
    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function pagos(){ return $this->hasMany(SuscripcionPago::class)->latest('fecha_pago'); }
}
