<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthSession extends Model
{
    protected $fillable = [
        'usuario_id','tipo','session_key_hash','personal_access_token_id','device_name','platform','browser',
        'ip_address','fingerprint_hash','last_seen_at','expires_at','revoked_at','revoked_reason',
    ];

    protected $casts = [
        'last_seen_at'=>'datetime',
        'expires_at'=>'datetime',
        'revoked_at'=>'datetime',
    ];

    public function usuario() { return $this->belongsTo(Usuario::class); }
}
