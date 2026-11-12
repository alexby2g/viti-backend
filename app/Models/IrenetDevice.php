<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IrenetDevice extends Model
{
    protected $table = 'irenet_devices';

    protected $fillable = [
        'usuario_id',
        'nombre',
        'token_hash',
        'expira_at',
        'revocado_at',
        'ultimo_acceso_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expira_at' => 'datetime',
        'revocado_at' => 'datetime',
        'ultimo_acceso_at' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
