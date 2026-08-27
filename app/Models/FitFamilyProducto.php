<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FitFamilyProducto extends Model
{
    use SoftDeletes;

    protected $table = 'fitfamily_productos';
    protected $fillable = ['empresa_id','aplicacion_id','categoria_id','nombre','slug','descripcion','imagen_url','precio','stock','disponible','visible_catalogo','datos_nutricionales'];
    protected $casts = ['precio'=>'decimal:2','stock'=>'integer','disponible'=>'boolean','visible_catalogo'=>'boolean','datos_nutricionales'=>'array'];

    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function aplicacion(){ return $this->belongsTo(Aplicacion::class); }
    public function categoria(){ return $this->belongsTo(FitFamilyCategoria::class,'categoria_id'); }
    public function detallesPedido(){ return $this->hasMany(FitFamilyPedidoDetalle::class,'producto_id'); }
}
