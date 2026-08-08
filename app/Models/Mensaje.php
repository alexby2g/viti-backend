<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Mensaje extends Model
{
    protected $table='mensajes';
    protected $fillable=['conversacion_id','usuario_id','tipo','mensaje','archivo_path','archivo_nombre','archivo_mime','archivo_tamano','leido_at'];
    protected $casts=['leido_at'=>'datetime','archivo_tamano'=>'integer'];
    protected $appends=['archivo_url'];

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
}
