<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class SolicitudSistema extends Model
{
    use SoftDeletes;
    protected $table='solicitudes_sistema';
    protected $fillable=[
        'empresa_id','cliente_id','cuestionario_id','plan_viti_id','asignado_a','codigo','public_token','publico_habilitado','titulo','resumen','estado','prioridad',
        'draft_revision','draft_saved_at','fecha_limite_deseada','presupuesto_estimado','forma_pago_preferida','frecuencia_suscripcion_preferida','acuerdo_comercial_requerido','acuerdo_comercial_aceptado',
        'acuerdo_comercial_nombre','acuerdo_comercial_fecha','enviado_at','aprobado_at','declaracion_aceptada','declaracion_nombre','declaracion_fecha'
    ];
    protected $hidden=['public_token'];
    protected $appends=['enlace_publico'];
    protected $casts=[
        'draft_revision'=>'integer','draft_saved_at'=>'datetime',
        'fecha_limite_deseada'=>'date:Y-m-d','presupuesto_estimado'=>'decimal:2','enviado_at'=>'datetime','aprobado_at'=>'datetime','publico_habilitado'=>'boolean',
        'acuerdo_comercial_requerido'=>'boolean','acuerdo_comercial_aceptado'=>'boolean','acuerdo_comercial_fecha'=>'date:Y-m-d',
        'declaracion_aceptada'=>'boolean','declaracion_fecha'=>'date:Y-m-d'
    ];

    public function getEnlacePublicoAttribute(): string
    {
        return rtrim((string) env('FRONTEND_APP_URL', 'http://localhost:9000'), '/').'/solicitar/'.$this->public_token;
    }
    public function empresa(){return $this->belongsTo(Empresa::class);}
    public function cliente(){return $this->belongsTo(Cliente::class);}
    public function cuestionario(){return $this->belongsTo(Cuestionario::class);}
    public function planViti(){return $this->belongsTo(PlanViti::class,'plan_viti_id');}
    public function asignado(){return $this->belongsTo(Usuario::class,'asignado_a');}
    public function respuestas(){return $this->hasMany(SolicitudRespuesta::class,'solicitud_id');}
    public function proyecto(){return $this->hasOne(Proyecto::class,'solicitud_id');}
    public function conversacion(){return $this->hasOne(Conversacion::class,'solicitud_id');}
    public function archivos(){return $this->morphMany(Archivo::class,'adjuntable');}
}