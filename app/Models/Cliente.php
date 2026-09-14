<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Cliente extends Model
{
    use SoftDeletes;

    protected $table = 'clientes';
    protected $casts = ['foto_verificada'=>'boolean','perfil_completo_at'=>'datetime'];
    protected $appends = ['foto_url'];

    protected $fillable = ['nombre','telefono','whatsapp','whatsapp_business','correo','documento','ci_expedido','ciudad','direccion','foto_path','foto_verificada','perfil_completo_at','observaciones','estado','canal_origen'];

    public function getFotoUrlAttribute(): ?string
    {
        if (!$this->foto_path) return null;

        $base = rtrim((string) config('filesystems.disks.public.url'), '/');
        if ($base !== '') return $base.'/'.ltrim($this->foto_path, '/');

        try {
            return Storage::disk('public')->url($this->foto_path);
        } catch (\Throwable) {
            return null;
        }
    }

    public function usuario() { return $this->hasOne(Usuario::class); }
    public function invitaciones() { return $this->hasMany(InvitacionCliente::class); }
    public function empresas() { return $this->hasMany(Empresa::class); }
    public function solicitudes() { return $this->hasMany(SolicitudSistema::class); }
    public function proyectos() { return $this->hasMany(Proyecto::class); }
    public function conversaciones() { return $this->hasMany(Conversacion::class); }
    public function archivos() { return $this->morphMany(Archivo::class, 'adjuntable'); }
}
