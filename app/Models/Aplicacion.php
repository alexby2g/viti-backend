<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Aplicacion extends Model
{
    use SoftDeletes;
    protected $table='aplicaciones';
    protected $fillable=['empresa_id','proyecto_id','nombre','slug','version','tipo','tecnologias','entorno','estado','url','url_administracion','repositorio_url','proveedor_hosting','notas','publicado_at'];
    protected $casts=['publicado_at'=>'datetime'];
    public function empresa(){return $this->belongsTo(Empresa::class);}
    public function proyecto(){return $this->belongsTo(Proyecto::class);}
    public function mantenimientos(){return $this->hasMany(Mantenimiento::class);}
    public function archivos(){return $this->morphMany(Archivo::class,'adjuntable');}
}
