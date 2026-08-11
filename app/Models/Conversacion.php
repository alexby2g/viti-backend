<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Conversacion extends Model
{
    use SoftDeletes;
    protected $table='conversaciones';
    protected $fillable=['cliente_id','electrofrio_cliente_id','empresa_id','aplicacion_id','responsable_usuario_id','solicitud_id','proyecto_id','asunto','estado','contexto','canal_principal','ultimo_mensaje_at','eliminada_por_usuario_id'];
    protected $casts=['ultimo_mensaje_at'=>'datetime','canal_principal'=>'boolean','deleted_at'=>'datetime'];
    public function cliente(){return $this->belongsTo(Cliente::class);}
    public function electrofrioCliente(){return $this->belongsTo(ElectrofrioCliente::class, 'electrofrio_cliente_id');}
    public function empresa(){return $this->belongsTo(Empresa::class);}
    public function aplicacion(){return $this->belongsTo(Aplicacion::class);}
    public function responsable(){return $this->belongsTo(Usuario::class,'responsable_usuario_id');}
    public function solicitud(){return $this->belongsTo(SolicitudSistema::class);}
    public function proyecto(){return $this->belongsTo(Proyecto::class);}
    public function mensajes(){return $this->hasMany(Mensaje::class);}
    public function llamadas(){return $this->hasMany(Llamada::class);}
    public function sesionesAtencion(){return $this->hasMany(AtencionSesion::class);}
    public function presencias(){return $this->hasMany(ChatPresencia::class);}

    public function setRelation($relation, $value)
    {
        if ($relation === 'mensajes' && $value instanceof EloquentCollection && $value->count() > 1) {
            $value = $value->sort(function ($left, $right): int {
                $leftDate = $left->created_at?->format('Y-m-d H:i:s.u') ?? '';
                $rightDate = $right->created_at?->format('Y-m-d H:i:s.u') ?? '';
                $dateComparison = $leftDate <=> $rightDate;
                return $dateComparison !== 0 ? $dateComparison : ((int) $left->id <=> (int) $right->id);
            })->values();
        }

        return parent::setRelation($relation, $value);
    }
}
