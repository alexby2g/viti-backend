<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FitFamilyPedidoDetalle extends Model
{
    protected $table = 'fitfamily_pedido_detalles';
    protected $fillable = ['pedido_id','producto_id','producto_nombre','precio_unitario','cantidad','subtotal'];
    protected $casts = ['precio_unitario'=>'decimal:2','cantidad'=>'integer','subtotal'=>'decimal:2'];

    public function pedido(){ return $this->belongsTo(FitFamilyPedido::class,'pedido_id'); }
    public function producto(){ return $this->belongsTo(FitFamilyProducto::class,'producto_id'); }
}
