<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeluqueriaPersonal extends Model
{
    protected $table = 'peluqueria_personal';
    protected $fillable = ['empresa_id','nombre','telefono','especialidad','horario_inicio','horario_fin','porcentaje_comision','activo'];
    protected $casts = ['porcentaje_comision'=>'decimal:2','activo'=>'boolean'];

    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function citas(){ return $this->hasMany(PeluqueriaCita::class,'personal_id'); }
    public function atenciones(){ return $this->hasMany(PeluqueriaAtencion::class,'personal_id'); }
}
