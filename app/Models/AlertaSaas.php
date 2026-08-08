<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertaSaas extends Model
{
    protected $table = 'alertas_saas';
    protected $fillable = ['usuario_id','empresa_id','aplicacion_id','clave','tipo','titulo','mensaje','ruta','leida_at'];
    protected $casts = ['leida_at'=>'datetime'];
    public function usuario(){ return $this->belongsTo(Usuario::class,'usuario_id'); }
    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function aplicacion(){ return $this->belongsTo(Aplicacion::class); }
}
