<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FitFamilyPedido extends Model
{
    use SoftDeletes;

    protected $table = 'fitfamily_pedidos';
    protected $fillable = ['empresa_id','aplicacion_id','cliente_id','codigo','estado','subtotal','total','notas','direccion_entrega','aceptado_at','preparado_at','entregado_at','rechazado_at'];
    protected $casts = ['subtotal'=>'decimal:2','total'=>'decimal:2','aceptado_at'=>'datetime','preparado_at'=>'datetime','entregado_at'=>'datetime','rechazado_at'=>'datetime'];

    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function aplicacion(){ return $this->belongsTo(Aplicacion::class); }
    public function cliente(){ return $this->belongsTo(Cliente::class); }
    public function detalles(){ return $this->hasMany(FitFamilyPedidoDetalle::class,'pedido_id'); }
}
