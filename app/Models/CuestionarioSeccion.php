<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CuestionarioSeccion extends Model
{
    protected $table='cuestionario_secciones';
    protected $fillable=['cuestionario_id','numero','titulo','descripcion','orden'];
    public function cuestionario(){return $this->belongsTo(Cuestionario::class);}
    public function preguntas(){return $this->hasMany(CuestionarioPregunta::class,'seccion_id')->orderBy('orden');}
}
