<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Proyecto extends Model
{
    use SoftDeletes;
    protected $table='proyectos';
    protected $fillable=['solicitud_id','empresa_id','cliente_id','responsable_id','codigo','nombre','descripcion','fase','estado','progreso','fecha_inicio','fecha_beta','fecha_entrega','repositorio_url','produccion_url','observaciones','precio_acordado','anticipo_monto','saldo_monto','estado_pago'];
    protected $casts=['progreso'=>'integer','fecha_inicio'=>'date:Y-m-d','fecha_beta'=>'date:Y-m-d','fecha_entrega'=>'date:Y-m-d','precio_acordado'=>'decimal:2','anticipo_monto'=>'decimal:2','saldo_monto'=>'decimal:2'];
    public function solicitud(){return $this->belongsTo(SolicitudSistema::class,'solicitud_id');}
    public function empresa(){return $this->belongsTo(Empresa::class);}
    public function cliente(){return $this->belongsTo(Cliente::class);}
    public function responsable(){return $this->belongsTo(Usuario::class,'responsable_id');}
    public function avances(){return $this->hasMany(ProyectoAvance::class)->latest();}
    public function aplicacion(){return $this->hasOne(Aplicacion::class);}
    public function pagos(){return $this->hasMany(ProyectoPago::class)->latest('fecha_pago');}
    public function archivos(){return $this->morphMany(Archivo::class,'adjuntable');}
}
