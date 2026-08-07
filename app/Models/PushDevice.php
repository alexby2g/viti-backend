<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushDevice extends Model
{
    protected $fillable = [
        'usuario_id', 'token', 'plataforma', 'dispositivo', 'activo', 'ultimo_registro_at',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'ultimo_registro_at' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
