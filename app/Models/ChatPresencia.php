<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatPresencia extends Model
{
    protected $table = 'chat_presencias';
    protected $fillable = ['conversacion_id', 'usuario_id', 'ultimo_ping_at', 'escribiendo_hasta'];
    protected $casts = ['ultimo_ping_at' => 'datetime', 'escribiendo_hasta' => 'datetime'];

    public function conversacion() { return $this->belongsTo(Conversacion::class); }
    public function usuario() { return $this->belongsTo(Usuario::class); }
}
