<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeluqueriaProductoMovimiento extends Model
{
    protected $table = 'peluqueria_producto_movimientos';
    protected $fillable = ['empresa_id','producto_id','atencion_id','tipo','cantidad','costo_unitario','precio_unitario','motivo','registrado_at'];
    protected $casts = ['cantidad'=>'decimal:2','costo_unitario'=>'decimal:2','precio_unitario'=>'decimal:2','registrado_at'=>'datetime'];

    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function producto(){ return $this->belongsTo(PeluqueriaProducto::class,'producto_id'); }
    public function atencion(){ return $this->belongsTo(PeluqueriaAtencion::class,'atencion_id'); }
}
