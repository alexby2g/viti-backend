<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeluqueriaAtencion extends Model
{
    protected $table = 'peluqueria_atenciones';
    protected $fillable = ['empresa_id','cita_id','cliente_id','servicio_id','personal_id','estado','iniciada_at','finalizada_at','precio_servicio','descuento','total','observaciones'];
    protected $casts = ['iniciada_at'=>'datetime','finalizada_at'=>'datetime','precio_servicio'=>'decimal:2','descuento'=>'decimal:2','total'=>'decimal:2'];

    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function cita(){ return $this->belongsTo(PeluqueriaCita::class,'cita_id'); }
    public function cliente(){ return $this->belongsTo(PeluqueriaCliente::class,'cliente_id'); }
    public function servicio(){ return $this->belongsTo(PeluqueriaServicio::class,'servicio_id'); }
    public function personal(){ return $this->belongsTo(PeluqueriaPersonal::class,'personal_id'); }
    public function pagos(){ return $this->hasMany(PeluqueriaPago::class,'atencion_id'); }
}
