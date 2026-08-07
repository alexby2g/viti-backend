<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlamadaSenal extends Model
{
    protected $table = 'llamada_senales';
    protected $fillable = ['llamada_id', 'usuario_id', 'tipo', 'payload'];
    protected $casts = ['payload' => 'array'];

    public function llamada() { return $this->belongsTo(Llamada::class); }
    public function usuario() { return $this->belongsTo(Usuario::class); }
}
