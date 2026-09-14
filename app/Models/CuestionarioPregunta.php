<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CuestionarioPregunta extends Model
{
    protected $table='cuestionario_preguntas';
    protected $fillable=['seccion_id','numero','pregunta','tipo','opciones','ayuda','obligatoria','activo','orden'];
    protected $casts=['opciones'=>'array','obligatoria'=>'boolean','activo'=>'boolean'];
    public function seccion(){return $this->belongsTo(CuestionarioSeccion::class,'seccion_id');}
    public function respuestas(){return $this->hasMany(SolicitudRespuesta::class,'pregunta_id');}
}
