<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectrofrioCliente extends Model
{
    protected $table = 'electrofrio_clientes';
    protected $fillable = ['empresa_id','nombre','telefono','direccion','referencia','observaciones','activo'];
    protected $casts = ['activo'=>'boolean'];

    public function empresa(){ return $this->belongsTo(Empresa::class); }
    public function usuario(){ return $this->hasOne(Usuario::class, 'electrofrio_cliente_id'); }
    public function conversaciones(){ return $this->hasMany(Conversacion::class, 'electrofrio_cliente_id'); }
    public function equipos(){ return $this->hasMany(ElectrofrioEquipo::class, 'cliente_id'); }
}
