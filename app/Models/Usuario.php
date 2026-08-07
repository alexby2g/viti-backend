<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'usuarios';
    protected $appends = ['foto_url'];

    protected $fillable = [
        'cliente_id', 'nombre', 'apellido', 'usuario', 'telefono', 'foto_path', 'password', 'rol', 'estado', 'ultimo_acceso',
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

    public function getFotoUrlAttribute(): ?string
    {
        return $this->foto_path ? Storage::disk('public')->url($this->foto_path) : null;
    }

    public function cliente() { return $this->belongsTo(Cliente::class); }

    public function isSuperAdmin(): bool
    {
        return $this->rol === 'superadmin';
    }
}
