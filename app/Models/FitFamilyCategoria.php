<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FitFamilyCategoria extends Model
{
    use SoftDeletes;

    protected $table = 'fitfamily_categorias';
    protected $fillable = ['empresa_id','aplicacion_id','nombre','slug','descripcion','activo','orden'];
    protected $casts = ['activo'=>'boolean','orden'=>'integer'];

    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function aplicacion(){ return $this->belongsTo(Aplicacion::class); }
    public function productos(){ return $this->hasMany(FitFamilyProducto::class,'categoria_id'); }
}
