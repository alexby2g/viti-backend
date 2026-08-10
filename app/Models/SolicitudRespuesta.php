<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SolicitudRespuesta extends Model
{
    protected $table='solicitud_respuestas';
    protected $fillable=['solicitud_id','pregunta_id','respuesta_texto','respuesta_json','origen'];
    protected $casts=['respuesta_json'=>'array'];
    public function pregunta(){return $this->belongsTo(CuestionarioPregunta::class,'pregunta_id');}
}
