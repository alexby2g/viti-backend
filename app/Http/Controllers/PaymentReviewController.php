<?php

namespace App\Http\Controllers;

use App\Models\{Empresa,Proyecto,ProyectoPago,Suscripcion,SuscripcionPago};
use App\Services\SubscriptionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentReviewController extends Controller
{
    public function confirmarProyecto(Request $request, ProyectoPago $pago): JsonResponse
    {
        $paymentId = (int) $pago->id;
        $projectId = (int) $pago->proyecto_id;

        DB::transaction(function () use ($request,$paymentId,$projectId): void {
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
        }, 3);

        return response()->json([
            'message'=>'Pago confirmado. El saldo del proyecto fue actualizado.',
            'data'=>ProyectoPago::query()->findOrFail($paymentId)->load(['pagador:id,nombre,apellido','revisor:id,nombre,apellido']),
        ]);
    }

    public function rechazarProyecto(Request $request, ProyectoPago $pago): JsonResponse
    {
        $data=$request->validate(['motivo'=>['required','string','min:5','max:500']]);
        $paymentId = (int) $pago->id;

        DB::transaction(function () use ($request,$data,$paymentId): void {
            $locked = ProyectoPago::query()->lockForUpdate()->findOrFail($paymentId);
            abort_unless($locked->estado_revision === 'pendiente_revision',422,'Este comprobante ya fue revisado.');
            $locked->update([
                'estado_revision'=>'rechazado',
                'revisado_at'=>now(),
                'revisado_por'=>$request->user()->id,
                'motivo_revision'=>$data['motivo'],
            ]);
        }, 3);

        return response()->json([
            'message'=>'Comprobante rechazado. El cliente podrá ver el motivo y enviar uno nuevo.',
            'data'=>ProyectoPago::query()->findOrFail($paymentId)->load(['pagador:id,nombre,apellido','revisor:id,nombre,apellido']),
        ]);
    }

    public function confirmarSuscripcion(Request $request, SuscripcionPago $pago, SubscriptionAccessService $access): JsonResponse
    {
        $paymentId = (int) $pago->id;
        $subscriptionId = (int) $pago->suscripcion_id;

        $paymentCompleted = DB::transaction(function () use ($request,$paymentId,$subscriptionId): bool {
            $subscription = Suscripcion::query()->lockForUpdate()->findOrFail($subscriptionId);
            $locked = SuscripcionPago::query()->lockForUpdate()->findOrFail($paymentId);
            abort_unless($locked->estado_revision === 'pendiente_revision',422,'Este comprobante ya fue revisado.');

            $locked->update([
                'estado_revision'=>'confirmado',
                'revisado_at'=>now(),
                'revisado_por'=>$request->user()->id,
                'motivo_revision'=>null,
            ]);
            $completed = $this->applySubscriptionPayment($subscription,$locked);
            Empresa::whereKey($locked->empresa_id)->update(['metodo_pago_preferido'=>$locked->metodo]);
            return $completed;
        }, 3);

        // Solo se refresca el estado de acceso cuando el cobro quedó completamente
        // satisfecho. Un pago parcial no debe convertir una suscripción suspendida
        // o en gracia en activa.
        if ($paymentCompleted) {
            $subscription = Suscripcion::query()->findOrFail($subscriptionId);
            $access->refresh($subscription);
        }

        return response()->json([
            'message'=>$paymentCompleted
                ? 'Pago de suscripción confirmado. La vigencia fue actualizada.'
                : 'Pago de suscripción confirmado como abono. La vigencia no cambia hasta completar el primer cobro.',
            'data'=>SuscripcionPago::query()->findOrFail($paymentId)->load(['pagador:id,nombre,apellido','revisor:id,nombre,apellido']),
        ]);
    }

    public function rechazarSuscripcion(Request $request, SuscripcionPago $pago): JsonResponse
    {
        $data=$request->validate(['motivo'=>['required','string','min:5','max:500']]);
        $paymentId = (int) $pago->id;

        DB::transaction(function () use ($request,$data,$paymentId): void {
            $locked = SuscripcionPago::query()->lockForUpdate()->findOrFail($paymentId);
            abort_unless($locked->estado_revision === 'pendiente_revision',422,'Este comprobante ya fue revisado.');
            $locked->update([
                'estado_revision'=>'rechazado',
                'revisado_at'=>now(),
                'revisado_por'=>$request->user()->id,
                'motivo_revision'=>$data['motivo'],
            ]);
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

    private function applySubscriptionPayment(Suscripcion $subscription, SuscripcionPago $payment): bool
    {
        if ($subscription->estado === 'cancelada') {
            abort(422, 'No se puede aplicar un pago a una suscripción cancelada.');
        }

        $confirmedTotal=(float)$subscription->pagos()->where('estado_revision','confirmado')->sum('monto');
        $firstPaymentDone=(bool)$subscription->primer_cobro_pagado;
        if(!$firstPaymentDone && $subscription->primer_cobro_monto !== null){
            $firstPaymentDone=$confirmedTotal+0.001 >= (float)$subscription->primer_cobro_monto;
        }

        if(!$firstPaymentDone && $subscription->primer_cobro_monto !== null){
            // Un pago parcial confirma el comprobante, pero no compra vigencia ni
            // cambia el estado de acceso. El saldo pendiente se conserva.
            $subscription->update(['primer_cobro_pagado'=>false]);
            return false;
        }

        if ($subscription->primer_cobro_hasta && !$subscription->primer_cobro_pagado) {
            $base=Carbon::parse($subscription->primer_cobro_hasta);
            $nextDue=$subscription->frecuencia==='anual'
                ? $base->addYear()
                : $base->addMonthNoOverflow();
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
        return true;
    }

    private function streamProof(?string $path, ?string $name, ?string $mime): StreamedResponse
    {
        abort_unless($path && Storage::disk('private_uploads')->exists($path),404,'El comprobante ya no está disponible.');
        $stream=Storage::disk('private_uploads')->readStream($path);
        abort_unless(is_resource($stream),404,'No pudimos abrir el comprobante.');
        return response()->streamDownload(function()use($stream):void{fpassthru($stream);fclose($stream);},$name?:'comprobante',["Content-Type"=>$mime?:'application/octet-stream','Cache-Control'=>'private, no-store']);
    }
}
