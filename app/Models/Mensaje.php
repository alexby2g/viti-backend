<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Mensaje extends Model
{
    protected $table='mensajes';
    protected $fillable=['conversacion_id','usuario_id','tipo','mensaje','archivo_path','archivo_nombre','archivo_mime','archivo_tamano','client_request_id','entregado_at','leido_at','editado_at','eliminado_at','eliminado_por_usuario_id'];
    protected $casts=['entregado_at'=>'datetime','leido_at'=>'datetime','editado_at'=>'datetime','eliminado_at'=>'datetime','archivo_tamano'=>'integer'];
    protected $appends=['archivo_url','eliminado'];

    public function conversacion(){return $this->belongsTo(Conversacion::class);}
    public function usuario(){return $this->belongsTo(Usuario::class);}

    protected function archivoUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (!$this->archivo_path) return null;
            try {
                return Storage::disk('private_uploads')->temporaryUrl($this->archivo_path, now()->addMinutes(30));
            } catch (\Throwable) {
                return null;
            }
        });
    }

    protected function eliminado(): Attribute
    {
        return Attribute::get(fn (): bool => (bool) $this->eliminado_at);
    }
}
