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
        abort_unless($pago->estado_revision === 'pendiente_revision',422,'Este comprobante ya fue revisado.');

        DB::transaction(function () use ($request,$pago): void {
            $pago->update([
                'estado_revision'=>'confirmado',
                'revisado_at'=>now(),
                'revisado_por'=>$request->user()->id,
                'motivo_revision'=>null,
            ]);
            Empresa::whereKey($pago->empresa_id)->update(['metodo_pago_preferido'=>$pago->metodo]);
            $this->refreshProject($pago->proyecto()->firstOrFail());
        });

        return response()->json(['message'=>'Pago confirmado. El saldo del proyecto fue actualizado.','data'=>$pago->fresh()->load(['pagador:id,nombre,apellido','revisor:id,nombre,apellido'])]);
    }

    public function rechazarProyecto(Request $request, ProyectoPago $pago): JsonResponse
    {
        abort_unless($pago->estado_revision === 'pendiente_revision',422,'Este comprobante ya fue revisado.');
        $data=$request->validate(['motivo'=>['required','string','min:5','max:500']]);
        $pago->update([
            'estado_revision'=>'rechazado',
            'revisado_at'=>now(),
            'revisado_por'=>$request->user()->id,
            'motivo_revision'=>$data['motivo'],
        ]);
        return response()->json(['message'=>'Comprobante rechazado. El cliente podrá ver el motivo y enviar uno nuevo.','data'=>$pago->fresh()->load(['pagador:id,nombre,apellido','revisor:id,nombre,apellido'])]);
    }

    public function confirmarSuscripcion(Request $request, SuscripcionPago $pago, SubscriptionAccessService $access): JsonResponse
    {
        abort_unless($pago->estado_revision === 'pendiente_revision',422,'Este comprobante ya fue revisado.');
        $subscription=$pago->suscripcion()->firstOrFail();

        DB::transaction(function () use ($request,$pago,$subscription): void {
            $pago->update([
                'estado_revision'=>'confirmado',
                'revisado_at'=>now(),
                'revisado_por'=>$request->user()->id,
                'motivo_revision'=>null,
            ]);
            $this->applySubscriptionPayment($subscription,$pago);
            Empresa::whereKey($pago->empresa_id)->update(['metodo_pago_preferido'=>$pago->metodo]);
        });

        $access->refresh($subscription->fresh());
        return response()->json(['message'=>'Pago de suscripción confirmado. La vigencia fue actualizada.','data'=>$pago->fresh()->load(['pagador:id,nombre,apellido','revisor:id,nombre,apellido'])]);
    }

    public function rechazarSuscripcion(Request $request, SuscripcionPago $pago): JsonResponse
    {
        abort_unless($pago->estado_revision === 'pendiente_revision',422,'Este comprobante ya fue revisado.');
        $data=$request->validate(['motivo'=>['required','string','min:5','max:500']]);
        $pago->update([
            'estado_revision'=>'rechazado',
            'revisado_at'=>now(),
            'revisado_por'=>$request->user()->id,
            'motivo_revision'=>$data['motivo'],
        ]);
        return response()->json(['message'=>'Comprobante de suscripción rechazado.','data'=>$pago->fresh()->load(['pagador:id,nombre,apellido','revisor:id,nombre,apellido'])]);
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
