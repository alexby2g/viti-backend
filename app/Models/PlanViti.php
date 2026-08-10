<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanViti extends Model
{
    protected $table = 'planes_viti';
    protected $fillable = ['codigo','nombre','descripcion','precio_proyecto','modulos','max_usuarios','max_aplicaciones','activo'];
    protected $casts = [
        'activo'=>'boolean',
        'precio_proyecto'=>'decimal:2',
        'modulos'=>'array',
        'max_usuarios'=>'integer',
        'max_aplicaciones'=>'integer',
    ];
    public function empresas(){ return $this->hasMany(Empresa::class,'plan_viti_id'); }
}
