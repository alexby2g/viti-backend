<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Empresa extends Model
{
    use SoftDeletes;

    protected $table = 'empresas';
    protected $fillable = ['cliente_id','plan_viti_id','codigo','nombre_comercial','razon_social','actividad','telefono','whatsapp','ciudad','direccion','logo_path','observaciones','estado','moneda','metodo_pago_preferido','zona_horaria','configuracion'];
    protected $appends = ['logo_url'];
    protected $casts = ['configuracion'=>'array'];

    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo_path) return null;
        $base = rtrim((string) config('filesystems.disks.public.url'), '/');
        if ($base !== '') return $base.'/'.ltrim($this->logo_path, '/');
        try { return Storage::disk('public')->url($this->logo_path); }
        catch (\Throwable) { return null; }
    }

    public function cliente() { return $this->belongsTo(Cliente::class); }
    public function planViti(){ return $this->belongsTo(PlanViti::class,'plan_viti_id'); }
    public function usuarios()
    {
        return $this->belongsToMany(Usuario::class,'empresa_usuario','empresa_id','usuario_id')
            ->withPivot(['rol_negocio','permisos','activo'])->withTimestamps();
    }
    public function solicitudes() { return $this->hasMany(SolicitudSistema::class); }
    public function proyectos() { return $this->hasMany(Proyecto::class); }
    public function aplicaciones() { return $this->hasMany(Aplicacion::class); }
    public function archivos() { return $this->morphMany(Archivo::class, 'adjuntable'); }
}
