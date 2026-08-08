<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Auditoria extends Model
{
    protected $table='auditoria';
    protected $fillable=['usuario_id','empresa_id','aplicacion_id','accion','entidad_tipo','entidad_id','descripcion','datos','ip','user_agent'];
    protected $casts=['datos'=>'array'];
    public function usuario(){return $this->belongsTo(Usuario::class,'usuario_id');}
    public function empresa(){return $this->belongsTo(Empresa::class);}
    public function aplicacion(){return $this->belongsTo(Aplicacion::class);}
}
