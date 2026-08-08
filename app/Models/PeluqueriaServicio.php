<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeluqueriaServicio extends Model
{
    protected $table = 'peluqueria_servicios';
    protected $fillable = ['empresa_id','nombre','categoria','duracion_minutos','precio','descripcion','activo'];
    protected $casts = ['precio'=>'decimal:2','activo'=>'boolean'];

    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function citas(){ return $this->hasMany(PeluqueriaCita::class,'servicio_id'); }
    public function atenciones(){ return $this->hasMany(PeluqueriaAtencion::class,'servicio_id'); }
}
