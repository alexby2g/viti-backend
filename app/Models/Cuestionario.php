<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Cuestionario extends Model
{
    protected $table='cuestionarios';
    protected $fillable=['nombre','version','descripcion','activo'];
    protected $casts=['activo'=>'boolean'];
    public function secciones(){return $this->hasMany(CuestionarioSeccion::class)->where('activo',true)->orderBy('orden');}
    public function seccionesTodas(){return $this->hasMany(CuestionarioSeccion::class)->orderBy('orden');}
    public function solicitudes(){return $this->hasMany(SolicitudSistema::class,'cuestionario_id');}
}
