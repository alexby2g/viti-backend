<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeluqueriaCliente extends Model
{
    protected $table = 'peluqueria_clientes';
    protected $fillable = ['empresa_id','nombre','telefono','whatsapp','fecha_nacimiento','sexo','direccion','observaciones','activo'];
    protected $casts = ['fecha_nacimiento'=>'date','activo'=>'boolean'];

    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function citas(){ return $this->hasMany(PeluqueriaCita::class,'cliente_id'); }
    public function atenciones(){ return $this->hasMany(PeluqueriaAtencion::class,'cliente_id'); }
}
