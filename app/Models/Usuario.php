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
        'cliente_id', 'nombre', 'apellido', 'usuario', 'documento', 'telefono', 'correo', 'foto_path', 'password', 'rol', 'estado', 'ultimo_acceso',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'ultimo_acceso' => 'datetime',
    ];

    public function getAuthPasswordName(): string { return 'password'; }

    public function getFotoUrlAttribute(): ?string
    {
        if (!$this->foto_path) return null;
        $base = rtrim((string) config('filesystems.disks.public.url'), '/');
        if ($base !== '') return $base.'/'.ltrim($this->foto_path, '/');
        try { return Storage::disk('public')->url($this->foto_path); }
        catch (\Throwable) { return null; }
    }

    public function cliente() { return $this->belongsTo(Cliente::class); }
    public function negocios()
    {
        return $this->belongsToMany(Empresa::class,'empresa_usuario','usuario_id','empresa_id')
            ->withPivot(['rol_negocio','permisos','activo'])->withTimestamps();
    }
    public function alertasSaas(){ return $this->hasMany(AlertaSaas::class,'usuario_id'); }

    public function isSuperAdmin(): bool { return $this->rol === 'superadmin'; }
    public function isPlatformAdmin(): bool { return in_array($this->rol, ['superadmin', 'administrador'], true); }
}
