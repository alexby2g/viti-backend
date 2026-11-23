<?php

namespace App\Services;

use App\Models\{AlertaSaas,Aplicacion,ProyectoPago,SolicitudSistema,Suscripcion,SuscripcionPago,Usuario};
use Illuminate\Support\Carbon;

class SaasAlertService
{
    public function __construct(private SubscriptionAccessService $subscriptions) {}

    /**
     * Publicar un aviso interno por cada comprobante recibido.
     * La clave por pago impide duplicados y no cambia saldos.
     */
    public function paymentSubmitted(ProyectoPago|SuscripcionPago $payment): void
    {
        $isProject = $payment instanceof ProyectoPago;
        $kind = $isProject ? 'proyecto' : 'suscripcion';
        $reference = $isProject
            ? ($payment->proyecto?->codigo ?: 'Proyecto')
            : ($payment->suscripcion?->aplicacion?->nombre ?: 'Suscripción');
        $business = $payment->empresa?->nombre_comercial ?: 'Negocio';
        $amount = number_format((float)$payment->monto, 2, ',', '.');

        Usuario::query()->where('rol', 'superadmin')->where('estado', 'activo')
            ->get(['id','rol'])->each(function (Usuario $admin) use ($payment,$kind,$reference,$business,$amount): void {
                $this->ensure(
                    $admin,
                    'comprobante_'.$kind.'_'.$payment->id.'_recibido',
                    'pago',
                    'Nuevo comprobante de pago por revisar',
                    $business.' · '.$reference.' · Bs '.$amount.'. Pendiente de validación.',
                    '/pagos',
                    (int)$payment->empresa_id
                );
            });
    }

    /**
     * Notificar el resultado de la revisión al pagador y a administración.
     * Confirmado en VITI no equivale por sí solo a acreditación bancaria.
     */
    public function paymentReviewed(ProyectoPago|SuscripcionPago $payment): void
    {
        if (!in_array($payment->estado_revision, ['confirmado','rechazado'], true)) return;

        $isProject = $payment instanceof ProyectoPago;
        $kind = $isProject ? 'proyecto' : 'suscripcion';
        $reference = $isProject
            ? ($payment->proyecto?->codigo ?: 'Proyecto')
            : ($payment->suscripcion?->aplicacion?->nombre ?: 'Suscripción');
        $business = $payment->empresa?->nombre_comercial ?: 'Negocio';
        $amount = number_format((float)$payment->monto, 2, ',', '.');
        $approved = $payment->estado_revision === 'confirmado';
        $status = $approved ? 'Confirmado en VITI' : 'Rechazado';
        $reason = !$approved && $payment->motivo_revision
            ? ' Motivo: '.$payment->motivo_revision : '';
        $key = 'comprobante_'.$kind.'_'.$payment->id.'_'.($approved ? 'confirmado':'rechazado');

        if ($payment->pagador_usuario_id) {
            $payer = Usuario::find($payment->pagador_usuario_id);
            if ($payer && $payer->estado === 'activo') {
                $this->ensure(
                    $payer,$key,'pago',
                    $approved ? 'Comprobante revisado: confirmado en VITI' : 'Comprobante rechazado',
                    $business.' · '.$reference.' · Bs '.$amount.'. '.$status.'.'.$reason,
                    '/mi-pagos',(int)$payment->empresa_id
                );
            }
        }

        Usuario::query()->where('rol', 'superadmin')->where('estado','activo')
            ->get(['id','rol'])->each(function (Usuario $admin) use ($payment,$key,$reference,$business,$amount,$status): void {
                $this->ensure(
                    $admin,$key,'pago','Comprobante revisado: '.$status,
                    $business.' · '.$reference.' · Bs '.$amount.'.',
                    '/pagos',(int)$payment->empresa_id
                );
            });
    }

    /**
     * Rectificación del abono reconocido por error en VITI. Mantener la alerta
     * separada de la confirmación original para que el cliente vea el cambio.
     */
    public function paymentRectified(ProyectoPago $payment): void
    {
        if ($payment->estado_revision !== 'anulado') return;

        $project = $payment->proyecto;
        $business = $payment->empresa?->nombre_comercial ?: 'Negocio';
        $reference = $project?->codigo ?: 'Proyecto';
        $amount = number_format((float)$payment->monto, 2, ',', '.');
        $message = $business.' · '.$reference.' · Bs '.$amount.
            '. Se rectificó una confirmación en VITI: el importe ya no cuenta como pagado.'.
            ' Motivo: '.($payment->motivo_revision ?: 'Revisión administrativa.');

        if ($payment->pagador_usuario_id) {
            $payer = Usuario::find($payment->pagador_usuario_id);
            if ($payer && $payer->estado === 'activo') {
                $this->ensure(
                    $payer,'comprobante_proyecto_'.$payment->id.'_rectificado',
                    'pago','Confirmación de pago rectificada',
                    $message,'/mi-pagos',(int)$payment->empresa_id
                );
            }
        }
        Usuario::query()->where('rol','superadmin')->where('estado','activo')
            ->get(['id','rol'])->each(function(Usuario $admin) use ($payment,$message): void {
                $this->ensure(
                    $admin,'comprobante_proyecto_'.$payment->id.'_rectificado',
                    'pago','Confirmación de pago rectificada',
                    $message,'/pagos',(int)$payment->empresa_id
                );
            });
    }

    public function syncFor(Usuario $user): void
    {
        if ($user->isSuperAdmin()) $this->syncAdmin($user);
        else $this->syncClient($user);
    }

    private function syncAdmin(Usuario $user): void
    {
        Usuario::query()
            ->where('rol','cliente')
            ->where('created_at','>=',now()->subDay())
            ->latest('id')
            ->limit(25)
            ->get(['id','cliente_id','nombre','usuario','created_at'])
            ->each(function (Usuario $client) use ($user): void {
                $this->ensure(
                    $user,
                    'usuario_registrado_'.$client->id,
                    'registro',
                    'Nuevo usuario registrado',
                    trim($client->nombre ?: 'Cliente').' · @'.$client->usuario,
                    '/clientes'
                );
            });

        SolicitudSistema::query()
            ->with(['cliente:id,nombre','empresa:id,nombre_comercial'])
            ->where('estado','en_revision')
            ->whereNotNull('enviado_at')
            ->where('enviado_at','>=',now()->subDay())
            ->latest('enviado_at')
            ->limit(25)
            ->get()
            ->each(function (SolicitudSistema $request) use ($user): void {
                $business = $request->empresa?->nombre_comercial ?: 'Empresa sin nombre';
                $client = $request->cliente?->nombre ?: 'Cliente';
                $this->ensure(
                    $user,
                    'solicitud_enviada_'.$request->id,
                    'solicitud',
                    'Nueva solicitud enviada',
                    $request->codigo.' · '.$business.' · '.$client,
                    '/solicitudes/'.$request->id,
                    $request->empresa_id
                );
            });

        Aplicacion::query()->with('empresa:id,nombre_comercial')->where('estado','activo')->where('entorno','produccion')->where('acceso_cliente',false)->get()->each(function (Aplicacion $app) use ($user): void {
            $this->ensure($user,'entrega_'.$app->id,'entrega','Aplicación lista para entregar',($app->empresa?->nombre_comercial ?: 'Negocio').' · '.$app->nombre,'/aplicaciones',$app->empresa_id,$app->id);
        });

        Suscripcion::query()->with(['empresa:id,nombre_comercial','aplicacion:id,nombre'])->get()->each(function (Suscripcion $subscription) use ($user): void {
            $s = $this->subscriptions->refresh($subscription);
            if (!in_array($s->estado,['gracia','suspendida'],true)) return;
            $this->ensure($user,'sus_'.$s->id.'_'.$s->estado,'pago','Suscripción '.($s->estado==='gracia'?'en gracia':'suspendida'),($s->empresa?->nombre_comercial ?: 'Negocio').' · '.($s->aplicacion?->nombre ?: 'Aplicación'),'/pagos',$s->empresa_id,$s->aplicacion_id);
        });
    }

    private function syncClient(Usuario $user): void
    {
        $businessIds = $user->negocios()
            ->wherePivot('activo',true)
            ->wherePivotIn('rol_negocio',['propietario','administrador'])
            ->pluck('empresas.id');
        if ($businessIds->isEmpty()) return;

        Suscripcion::query()->with(['empresa:id,nombre_comercial','aplicacion:id,nombre'])
            ->whereIn('empresa_id',$businessIds)->get()
            ->each(function (Suscripcion $subscription) use ($user): void {
                $s = $this->subscriptions->refresh($subscription);
                $today = Carbon::today();
                $due = Carbon::parse($s->fecha_vencimiento);
                $days = $today->diffInDays($due,false);

                if ($s->estado === 'gracia') {
                    $this->ensure($user,'sus_gracia_'.$s->id.'_'.$due->toDateString(),'pago','Pago pendiente',($s->empresa?->nombre_comercial ?: 'Tu negocio').' está dentro del periodo de gracia.','/mi-pagos',$s->empresa_id,$s->aplicacion_id);
                } elseif ($s->estado === 'suspendida') {
                    $this->ensure($user,'sus_suspendida_'.$s->id.'_'.$due->toDateString(),'pago','Aplicación suspendida','Regulariza tu suscripción para recuperar el acceso a '.($s->aplicacion?->nombre ?: 'tu aplicación').'.','/mi-pagos',$s->empresa_id,$s->aplicacion_id);
                } elseif ($s->estado === 'activa' && $days >= 0 && $days <= 3) {
                    $this->ensure($user,'sus_vence_'.$s->id.'_'.$due->toDateString(),'pago','Tu suscripción vence pronto','Vence el '.$due->format('d/m/Y').' · '.number_format((float)$s->monto,2).' Bs.','/mi-pagos',$s->empresa_id,$s->aplicacion_id);
                }
            });
    }

    private function ensure(Usuario $user,string $key,string $type,string $title,string $message,string $path,?int $businessId=null,?int $appId=null): void
    {
        AlertaSaas::firstOrCreate(
            ['usuario_id'=>$user->id,'clave'=>$key],
            ['empresa_id'=>$businessId,'aplicacion_id'=>$appId,'tipo'=>$type,'titulo'=>$title,'mensaje'=>$message,'ruta'=>$path]
        );
    }
}
