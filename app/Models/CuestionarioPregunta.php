<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CuestionarioPregunta extends Model
{
    protected $table='cuestionario_preguntas';
    protected $fillable=['seccion_id','numero','pregunta','tipo','opciones','ayuda','obligatoria','orden'];
    protected $casts=['opciones'=>'array','obligatoria'=>'boolean'];
    public function seccion(){return $this->belongsTo(CuestionarioSeccion::class,'seccion_id');}
}
