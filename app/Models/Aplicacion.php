<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Aplicacion extends Model
{
    use SoftDeletes;
    protected $table='aplicaciones';
    protected $fillable=['empresa_id','proyecto_id','catalogo_aplicacion_id','aplicacion_origen_id','nombre','slug','descripcion','icono','color_primario','color_secundario','modulos','configuracion','es_plantilla','version','tipo','tecnologias','entorno','estado','acceso_cliente','acceso_bloqueado_manual','bloqueo_manual_motivo','bloqueado_manualmente_at','bloqueado_manualmente_por','entregado_at','provisionado_at','url','url_administracion','repositorio_url','proveedor_hosting','notas','publicado_at'];
    protected $casts=['publicado_at'=>'datetime','entregado_at'=>'datetime','provisionado_at'=>'datetime','bloqueado_manualmente_at'=>'datetime','acceso_cliente'=>'boolean','es_plantilla'=>'boolean','acceso_bloqueado_manual'=>'boolean','modulos'=>'array','configuracion'=>'array'];
    public function empresa(){return $this->belongsTo(Empresa::class);}
    public function proyecto(){return $this->belongsTo(Proyecto::class);}
    public function catalogo(){return $this->belongsTo(CatalogoAplicacion::class,'catalogo_aplicacion_id');}
    public function origen(){return $this->belongsTo(self::class,'aplicacion_origen_id');}
    public function clones(){return $this->hasMany(self::class,'aplicacion_origen_id');}
    public function usuarios(){return $this->belongsToMany(Usuario::class,'aplicacion_usuario','aplicacion_id','usuario_id')->withPivot(['rol','permisos','activo'])->withTimestamps();}
    public function suscripcion(){return $this->hasOne(Suscripcion::class);}
    public function mantenimientos(){return $this->hasMany(Mantenimiento::class);}
    public function archivos(){return $this->morphMany(Archivo::class,'adjuntable');}
}
