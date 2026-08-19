<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AlertaSaas extends Model
{
    protected $table='alertas_saas';
    protected $fillable=['usuario_id','empresa_id','aplicacion_id','clave','canal','categoria','tipo','titulo','mensaje','ruta','recurso_tipo','recurso_id','data','leida_at'];
    protected $casts=['data'=>'array','leida_at'=>'datetime'];
    public function usuario(){return $this->belongsTo(Usuario::class,'usuario_id');}
    public function empresa(){return $this->belongsTo(Empresa::class);}
    public function aplicacion(){return $this->belongsTo(Aplicacion::class);}
}
