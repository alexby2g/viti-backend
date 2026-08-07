<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Conversacion extends Model
{
    protected $table='conversaciones';
    protected $fillable=['cliente_id','solicitud_id','proyecto_id','asunto','estado','ultimo_mensaje_at'];
    protected $casts=['ultimo_mensaje_at'=>'datetime'];
    public function cliente(){return $this->belongsTo(Cliente::class);}
    public function solicitud(){return $this->belongsTo(SolicitudSistema::class);}
    public function proyecto(){return $this->belongsTo(Proyecto::class);}
    public function mensajes(){return $this->hasMany(Mensaje::class);}
}
