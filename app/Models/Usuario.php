<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'usuarios';

    protected $fillable = [
        'cliente_id', 'nombre', 'apellido', 'usuario', 'telefono', 'password', 'rol', 'estado', 'ultimo_acceso',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'ultimo_acceso' => 'datetime',
    ];

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function cliente() { return $this->belongsTo(Cliente::class); }

    public function isSuperAdmin(): bool
    {
        return $this->rol === 'superadmin';
    }
}
