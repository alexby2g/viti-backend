<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Cuestionario extends Model
{
    protected $table='cuestionarios';
    protected $fillable=['nombre','version','descripcion','activo'];
    protected $casts=['activo'=>'boolean'];
    public function secciones(){return $this->hasMany(CuestionarioSeccion::class)->orderBy('orden');}
}
