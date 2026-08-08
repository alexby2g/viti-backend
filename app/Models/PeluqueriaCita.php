<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeluqueriaCita extends Model
{
    protected $table = 'peluqueria_citas';
    protected $fillable = ['empresa_id','cliente_id','servicio_id','personal_id','fecha','hora_inicio','hora_fin','estado','notas'];
    protected $casts = ['fecha'=>'date'];

    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function cliente(){ return $this->belongsTo(PeluqueriaCliente::class,'cliente_id'); }
    public function servicio(){ return $this->belongsTo(PeluqueriaServicio::class,'servicio_id'); }
    public function personal(){ return $this->belongsTo(PeluqueriaPersonal::class,'personal_id'); }
    public function atencion(){ return $this->hasOne(PeluqueriaAtencion::class,'cita_id'); }
}
