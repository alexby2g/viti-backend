<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Mantenimiento extends Model
{
    use SoftDeletes;
    protected $table='mantenimientos';
    protected $fillable=['aplicacion_id','proyecto_id','empresa_id','cliente_id','asignado_a','creado_por','codigo','titulo','descripcion','tipo','prioridad','estado'];
    public function aplicacion(){return $this->belongsTo(Aplicacion::class);}
    public function proyecto(){return $this->belongsTo(Proyecto::class);}
    public function empresa(){return $this->belongsTo(Empresa::class);}
    public function cliente(){return $this->belongsTo(Cliente::class);}
    public function asignado(){return $this->belongsTo(Usuario::class,'asignado_a');}
    public function creador(){return $this->belongsTo(Usuario::class,'creado_por');}
}
