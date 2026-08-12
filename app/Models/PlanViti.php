<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanViti extends Model
{
    protected $table = 'planes_viti';
    protected $fillable = ['codigo','nombre','descripcion','precio_proyecto','precio_mensual','precio_anual','dias_prueba','modulos','max_usuarios','max_aplicaciones','activo'];
    protected $casts = [
        'activo'=>'boolean',
        'precio_proyecto'=>'decimal:2',
        'precio_mensual'=>'decimal:2',
        'precio_anual'=>'decimal:2',
        'dias_prueba'=>'integer',
        'modulos'=>'array',
        'max_usuarios'=>'integer',
        'max_aplicaciones'=>'integer',
    ];
    public function empresas(){ return $this->hasMany(Empresa::class,'plan_viti_id'); }
}
