<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProyectoAvance extends Model
{
    protected $table='proyecto_avances';
    protected $fillable=['proyecto_id','creado_por','fase','area','titulo','descripcion','progreso','visible_cliente'];
    protected $casts=['visible_cliente'=>'boolean','progreso'=>'integer'];
    public function proyecto(){return $this->belongsTo(Proyecto::class);}
    public function creador(){return $this->belongsTo(Usuario::class,'creado_por');}
    public function archivos(){return $this->morphMany(Archivo::class,'adjuntable');}
}
