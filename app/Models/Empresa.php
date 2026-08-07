<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Empresa extends Model
{
    use SoftDeletes;

    protected $table = 'empresas';
    protected $fillable = ['cliente_id','codigo','nombre_comercial','razon_social','actividad','telefono','whatsapp','ciudad','direccion','logo_path','observaciones','estado'];
    protected $appends = ['logo_url'];

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function cliente() { return $this->belongsTo(Cliente::class); }
    public function solicitudes() { return $this->hasMany(SolicitudSistema::class); }
    public function proyectos() { return $this->hasMany(Proyecto::class); }
    public function aplicaciones() { return $this->hasMany(Aplicacion::class); }
    public function archivos() { return $this->morphMany(Archivo::class, 'adjuntable'); }
}
