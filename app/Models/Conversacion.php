<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Conversacion extends Model
{
    protected $table='conversaciones';
    protected $fillable=['cliente_id','responsable_usuario_id','solicitud_id','proyecto_id','asunto','estado','canal_principal','ultimo_mensaje_at'];
    protected $casts=['ultimo_mensaje_at'=>'datetime','canal_principal'=>'boolean'];
    public function cliente(){return $this->belongsTo(Cliente::class);}
    public function responsable(){return $this->belongsTo(Usuario::class,'responsable_usuario_id');}
    public function solicitud(){return $this->belongsTo(SolicitudSistema::class);}
    public function proyecto(){return $this->belongsTo(Proyecto::class);}
    public function mensajes(){return $this->hasMany(Mensaje::class);}
    public function llamadas(){return $this->hasMany(Llamada::class);}
}
