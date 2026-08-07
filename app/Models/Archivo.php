<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Archivo extends Model
{
    protected $table='archivos';
    protected $fillable=['adjuntable_type','adjuntable_id','subido_por','categoria','nombre_original','ruta','mime','tamano','descripcion'];
    public function adjuntable(){return $this->morphTo();}
    public function usuario(){return $this->belongsTo(Usuario::class,'subido_por');}
}
