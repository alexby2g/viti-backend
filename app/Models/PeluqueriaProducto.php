<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeluqueriaProducto extends Model
{
    protected $table = 'peluqueria_productos';
    protected $fillable = ['empresa_id','nombre','categoria','tipo','unidad','stock','stock_minimo','costo','precio_venta','descripcion','activo'];
    protected $casts = ['stock'=>'decimal:2','stock_minimo'=>'decimal:2','costo'=>'decimal:2','precio_venta'=>'decimal:2','activo'=>'boolean'];

    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function movimientos(){ return $this->hasMany(PeluqueriaProductoMovimiento::class,'producto_id')->latest('registrado_at'); }
}
