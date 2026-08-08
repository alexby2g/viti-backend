<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogoAplicacion extends Model
{
    protected $table = 'catalogo_aplicaciones';
    protected $fillable = ['clave','nombre','descripcion','icono','tipo','ruta_base','activo','solicitable','orden'];
    protected $casts = ['activo'=>'boolean','solicitable'=>'boolean','orden'=>'integer'];
    public function aplicaciones(){ return $this->hasMany(Aplicacion::class,'catalogo_aplicacion_id'); }
}
