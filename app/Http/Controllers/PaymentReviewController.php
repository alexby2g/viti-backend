<?php

namespace App\Http\Controllers;

use App\Models\{Auditoria,Empresa,Proyecto,ProyectoPago,Suscripcion,SuscripcionPago};
use App\Services\{SaasAlertService,SubscriptionAccessService};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentReviewController extends Controller
{
    public function confirmarProyecto(Request $request, ProyectoPago $pago, SaasAlertService $alerts): JsonResponse
    {
        $paymentId = (int) $pago->id;
        $projectId = (int) $pago->proyecto_id;

        DB::transaction(function () use ($request,$paymentId,$projectId,$alerts): void {
            // El proyecto se bloquea primero para serializar cualquier cambio contable
            // relacionado con él. Así un doble clic no procesa dos veces el mismo pago.
            $project = Proyecto::query()->lockForUpdate()->findOrFail($projectId);
            $locked = ProyectoPago::query()->lockForUpdate()->findOrFail($paymentId);
            abort_unless($locked->estado_revision === 'pendiente_revision',422,'Este comprobante ya fue revisado.');

            $locked->update([
                'estado_revision'=>'confirmado',
                'revisado_at'=>now(),
                'revisado_por'=>$request->user()->id,
                'motivo_revision'=>null,
            ]);
            Empresa::whereKey($locked->empresa_id)->update(['metodo_pago_preferido'=>$locked->metodo]);
            $this->refreshProject($project);
            $alerts->paymentReviewed($locked);
        }, 3);

        return response()->json([
            'message'=>'Pago confirmado. El saldo del proyecto fue actualizado.',
            'data'=>ProyectoPago::query()->findOrFail($paymentId)->load(['pagador:id,nombre,apellido','revisor:id,nombre,apellido']),
        ]);
    }

    public function rechazarProyecto(Request $request, ProyectoPago $pago, SaasAlertService $alerts): JsonResponse
    {
        $data=$request->validate(['motivo'=>['required','string','min:5','max:500']]);
        $paymentId = (int) $pago->id;

        DB::transaction(function () use ($request,$data,$paymentId,$alerts): void {
            $locked = ProyectoPago::query()->lockForUpdate()->findOrFail($paymentId);
            abort_unless($locked->estado_revision === 'pendiente_revision',422,'Este comprobante ya fue revisado.');
            $locked->update([
                'estado_revision'=>'rechazado',
                'revisado_at'=>now(),
                'revisado_por'=>$request->user()->id,
                'motivo_revision'=>$data['motivo'],
            ]);
            $alerts->paymentReviewed($locked);
        }, 3);

        return response()->json([
            'message'=>'Comprobante rechazado. El cliente podrá ver el motivo y enviar uno nuevo.',
            'data'=>ProyectoPago::query()->findOrFail($paymentId)->load(['pagador:id,nombre,apellido','revisor:id,nombre,apellido']),
        ]);
    }

    /**
     * Rectifica una confirmación administrativa hecha por error sin borrar el
     * comprobante ni su historial. Solo se permite antes de publicar la entrega.
     * Nunca ejecuta una reversión bancaria; ajusta el registro interno de VITI.
     */
    public function anularConfirmacionProyecto(Request $request, ProyectoPago $pago, SaasAlertService $alerts): JsonResponse
    {
        $data = $request->validate([
            'motivo'=>['required','string','min:15','max:500'],
            'confirmacion'=>['required','in:ANULAR CONFIRMACION'],
        ]);
        $paymentId = (int) $pago->id;
        $projectId = (int) $pago->proyecto_id;

        DB::transaction(function () use ($request,$data,$paymentId,$projectId,$alerts): void {
            $project = Proyecto::query()->lockForUpdate()->findOrFail($projectId);
            $locked = ProyectoPago::query()->lockForUpdate()->findOrFail($paymentId);
            abort_unless((int) $locked->proyecto_id === (int) $project->id, 422, 'El comprobante no pertenece al proyecto.');
            abort_unless(
                $locked->estado_revision === 'confirmado' && $locked->origen === 'cliente',
                422, 'Solo puedes rectificar comprobantes del cliente que estén confirmados.'
            );
            abort_if(
                $project->entrega()->whereNotNull('publicado_at')->exists(),
                422, 'El proyecto ya tiene entrega publicada. Requiere conciliación formal fuera de esta operación.'
            );

            $previousStatus = $project->estado_pago;
            $previousReview = [
                'estado_revision'=>$locked->estado_revision,
                'revisado_por'=>$locked->revisado_por,
                'revisado_at'=>$locked->revisado_at?->toIso8601String(),
                'motivo_revision'=>$locked->motivo_revision,
            ];
            $locked->update([
                'estado_revision'=>'anulado',
                'motivo_revision'=>trim($data['motivo']),
                'revisado_por'=>$request->user()->id,
                'revisado_at'=>now(),
            ]);
            $this->refreshProject($project);
            $project->refresh();

            Auditoria::create([
                'usuario_id'=>$request->user()->id,
                'empresa_id'=>$locked->empresa_id,
                'accion'=>'pago_proyecto_confirmacion_rectificada',
                'entidad_tipo'=>ProyectoPago::class,
                'entidad_id'=>$locked->id,
                'descripcion'=>'Confirmación interna de comprobante rectificada sin borrar archivo ni evidencia.',
                'datos'=>[
                    'proyecto_id'=>$project->id,
                    'codigo'=>$project->codigo,
                    'importe'=>$locked->monto,
                    'estado_anterior'=>$previousStatus,
                    'estado_actual'=>$project->estado_pago,
                    'revision_anterior'=>$previousReview,
                    'revision_actual'=>'anulado',
                    'motivo'=>trim($data['motivo']),
                    'requiere_verificacion_bancaria'=>true,
                ],
                'ip'=>$request->ip(),
                'user_agent'=>$request->userAgent(),
            ]);
            $alerts->paymentRectified($locked);
        }, 3);

        return response()->json([
            'message'=>'Confirmación rectificada. Se conservó el comprobante y se recalculó el saldo interno.',
            'data'=>ProyectoPago::query()->findOrFail($paymentId)->load(['pagador:id,nombre,apellido','revisor:id,nombre,apellido']),
        ]);
    }

    public function confirmarSuscripcion(Request $request, SuscripcionPago $pago, SubscriptionAccessService $access, SaasAlertService $alerts): JsonResponse
    {
        $paymentId = (int) $pago->id;
        $subscriptionId = (int) $pago->suscripcion_id;

        DB::transaction(function () use ($request,$paymentId,$subscriptionId,$alerts): void {
            // La suscripción es el recurso contable que cambia de vigencia, por eso
            // se bloquea junto con el comprobante antes de revisar su estado.
            $subscription = Suscripcion::query()->lockForUpdate()->findOrFail($subscriptionId);
            $locked = SuscripcionPago::query()->lockForUpdate()->findOrFail($paymentId);
            abort_unless($locked->estado_revision === 'pendiente_revision',422,'Este comprobante ya fue revisado.');

            $locked->update([
                'estado_revision'=>'confirmado',
                'revisado_at'=>now(),
                'revisado_por'=>$request->user()->id,
                'motivo_revision'=>null,
            ]);
            $this->applySubscriptionPayment($subscription,$locked);
            Empresa::whereKey($locked->empresa_id)->update(['metodo_pago_preferido'=>$locked->metodo]);
            $alerts->paymentReviewed($locked);
        }, 3);

        $subscription = Suscripcion::query()->findOrFail($subscriptionId);
        $access->refresh($subscription);
        return response()->json([
            'message'=>'Pago de suscripción confirmado. La vigencia fue actualizada.',
            'data'=>SuscripcionPago::query()->findOrFail($paymentId)->load(['pagador:id,nombre,apellido','revisor:id,nombre,apellido']),
        ]);
    }

    public function rechazarSuscripcion(Request $request, SuscripcionPago $pago, SaasAlertService $alerts): JsonResponse
    {
        $data=$request->validate(['motivo'=>['required','string','min:5','max:500']]);
        $paymentId = (int) $pago->id;

        DB::transaction(function () use ($request,$data,$paymentId,$alerts): void {
            $locked = SuscripcionPago::query()->lockForUpdate()->findOrFail($paymentId);
            abort_unless($locked->estado_revision === 'pendiente_revision',422,'Este comprobante ya fue revisado.');
            $locked->update([
                'estado_revision'=>'rechazado',
                'revisado_at'=>now(),
                'revisado_por'=>$request->user()->id,
                'motivo_revision'=>$data['motivo'],
            ]);
            $alerts->paymentReviewed($locked);
        }, 3);

        return response()->json([
            'message'=>'Comprobante de suscripción rechazado.',
            'data'=>SuscripcionPago::query()->findOrFail($paymentId)->load(['pagador:id,nombre,apellido','revisor:id,nombre,apellido']),
        ]);
    }

    public function comprobanteProyecto(ProyectoPago $pago): StreamedResponse
    {
        return $this->streamProof($pago->comprobante_path,$pago->comprobante_nombre,$pago->comprobante_mime);
    }

    public function comprobanteSuscripcion(SuscripcionPago $pago): StreamedResponse
    {
        return $this->streamProof($pago->comprobante_path,$pago->comprobante_nombre,$pago->comprobante_mime);
    }

    private function refreshProject(Proyecto $project): void
    {
        if ($project->precio_acordado === null) {
            $project->update(['estado_pago'=>'sin_acuerdo']);
            return;
        }

        $payments=$project->pagos()->where('estado_revision','confirmado')->get();
        $initialTarget=(float)($project->anticipo_monto??0);
        $balanceTarget=(float)($project->saldo_monto??0);
        $initialPaid=(float)$payments->where('tipo','anticipo')->sum('monto');
        $balancePaid=(float)$payments->whereIn('tipo',['saldo_final','otro'])->sum('monto');

        $status='pendiente_anticipo';
        if($initialPaid+0.001 >= $initialTarget)$status='pendiente_saldo';
        if(($initialPaid+$balancePaid)+0.001 >= (float)$project->precio_acordado || ($initialPaid+0.001 >= $initialTarget && $balancePaid+0.001 >= $balanceTarget))$status='pagado';
        $project->update(['estado_pago'=>$status]);
    }

    private function applySubscriptionPayment(Suscripcion $subscription, SuscripcionPago $payment): void
    {
        $firstPaymentDone=(bool)$subscription->primer_cobro_pagado;
        if(!$firstPaymentDone && $subscription->primer_cobro_monto !== null){
            $confirmed=(float)$subscription->pagos()->where('estado_revision','confirmado')->sum('monto');
            $firstPaymentDone=$confirmed+0.001 >= (float)$subscription->primer_cobro_monto;
        }

        if(!$subscription->primer_cobro_pagado && $firstPaymentDone && $subscription->primer_cobro_hasta){
            $nextDue=Carbon::parse($subscription->primer_cobro_hasta)->addMonthNoOverflow()->endOfMonth();
        } else {
            $base=$subscription->fecha_vencimiento && $subscription->fecha_vencimiento->isFuture()
                ? $subscription->fecha_vencimiento->copy()
                : Carbon::parse($payment->fecha_pago);
            $nextDue=$subscription->frecuencia==='anual'?$base->addYear():$base->addMonthNoOverflow();
        }

        $subscription->update([
            'fecha_vencimiento'=>$nextDue->toDateString(),
            'primer_cobro_pagado'=>$firstPaymentDone,
            'estado'=>'activa',
        ]);
    }

    private function streamProof(?string $path, ?string $name, ?string $mime): StreamedResponse
    {
        abort_unless($path && Storage::disk('private_uploads')->exists($path),404,'El comprobante ya no está disponible.');
        $stream=Storage::disk('private_uploads')->readStream($path);
        abort_unless(is_resource($stream),404,'No pudimos abrir el comprobante.');
        return response()->streamDownload(function()use($stream):void{fpassthru($stream);fclose($stream);},$name?:'comprobante',["Content-Type"=>$mime?:'application/octet-stream','Cache-Control'=>'private, no-store']);
    }
}
