<?php

namespace App\Http\Controllers;

use App\Models\{Proyecto,ProyectoPago,Suscripcion,SuscripcionPago};
use App\Services\{SubscriptionAccessService,TenantContext};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientPaymentController extends Controller
{
    private const METHODS = ['qr','transferencia','efectivo','otro'];

    public function proyecto(Request $request, Proyecto $proyecto, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        abort_unless((int)$proyecto->empresa_id === (int)$empresa->id,403,'Este proyecto no pertenece a tu negocio.');
        abort_unless($proyecto->precio_acordado !== null,422,'El proyecto todavía no tiene un precio acordado.');

        $pendingExisting = ProyectoPago::query()
            ->where('proyecto_id',$proyecto->id)
            ->where('estado_revision','pendiente_revision')
            ->exists();
        abort_if($pendingExisting,422,'Ya existe un comprobante de este proyecto pendiente de revisión.');

        [$type,$expected] = $this->projectDue($proyecto);
        abort_if($expected <= 0,422,'Este proyecto no tiene un pago pendiente.');

        $data = $this->validateSubmission($request,$expected);
        $proof = $this->storeProof($request,'proyectos/'.$proyecto->id,$data['metodo']);

        $payment = ProyectoPago::create([
            'proyecto_id'=>$proyecto->id,
            'empresa_id'=>$empresa->id,
            'pagador_usuario_id'=>$request->user()->id,
            'tipo'=>$type,
            'monto'=>$data['monto'],
            'metodo'=>$data['metodo'],
            'fecha_pago'=>$data['fecha_pago'],
            'referencia'=>$data['referencia'] ?? null,
            'observaciones'=>$data['observaciones'] ?? null,
            'comprobante_path'=>$proof['path'],
            'comprobante_nombre'=>$proof['name'],
            'comprobante_mime'=>$proof['mime'],
            'registrado_por'=>$request->user()->id,
            'estado_revision'=>'pendiente_revision',
            'origen'=>'cliente',
            'enviado_at'=>now(),
        ]);

        return response()->json([
            'message'=>'Comprobante enviado. El pago quedará aplicado cuando AGR Studio lo confirme.',
            'data'=>$payment->load('pagador:id,nombre,apellido,usuario'),
        ],201);
    }

    public function suscripcion(Request $request, Suscripcion $suscripcion, TenantContext $tenants, SubscriptionAccessService $access): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        abort_unless((int)$suscripcion->empresa_id === (int)$empresa->id,403,'Esta suscripción no pertenece a tu negocio.');

        $status = $access->statusFor($suscripcion->aplicacion);
        abort_if($status['en_prueba'] ?? false,422,'Todavía estás dentro del periodo de prueba gratuita. El cobro comenzará después del '.$status['prueba_hasta'].'.');
        abort_if($suscripcion->estado === 'cancelada',422,'Esta suscripción está cancelada.');

        $pendingExisting = SuscripcionPago::query()
            ->where('suscripcion_id',$suscripcion->id)
            ->where('estado_revision','pendiente_revision')
            ->exists();
        abort_if($pendingExisting,422,'Ya existe un comprobante de suscripción pendiente de revisión.');

        $expected = $this->subscriptionDue($suscripcion);
        $data = $this->validateSubmission($request,$expected);
        $proof = $this->storeProof($request,'suscripciones/'.$suscripcion->id,$data['metodo']);

        $payment = SuscripcionPago::create([
            'suscripcion_id'=>$suscripcion->id,
            'empresa_id'=>$empresa->id,
            'pagador_usuario_id'=>$request->user()->id,
            'monto'=>$data['monto'],
            'metodo'=>$data['metodo'],
            'fecha_pago'=>$data['fecha_pago'],
            'referencia'=>$data['referencia'] ?? null,
            'observaciones'=>$data['observaciones'] ?? null,
            'comprobante_path'=>$proof['path'],
            'comprobante_nombre'=>$proof['name'],
            'comprobante_mime'=>$proof['mime'],
            'registrado_por'=>$request->user()->id,
            'estado_revision'=>'pendiente_revision',
            'origen'=>'cliente',
            'enviado_at'=>now(),
        ]);

        return response()->json([
            'message'=>'Comprobante de suscripción enviado. Se aplicará cuando AGR Studio lo confirme.',
            'data'=>$payment->load('pagador:id,nombre,apellido,usuario'),
        ],201);
    }

    public function comprobanteProyecto(Request $request, ProyectoPago $pago, TenantContext $tenants): StreamedResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        abort_unless((int)$pago->empresa_id === (int)$empresa->id,403,'No tienes permiso para abrir este comprobante.');
        return $this->streamProof($pago->comprobante_path,$pago->comprobante_nombre,$pago->comprobante_mime);
    }

    public function comprobanteSuscripcion(Request $request, SuscripcionPago $pago, TenantContext $tenants): StreamedResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        abort_unless((int)$pago->empresa_id === (int)$empresa->id,403,'No tienes permiso para abrir este comprobante.');
        return $this->streamProof($pago->comprobante_path,$pago->comprobante_nombre,$pago->comprobante_mime);
    }

    private function validateSubmission(Request $request, float $expected): array
    {
        $data = $request->validate([
            'monto'=>['required','numeric','min:0.01'],
            'metodo'=>['required',Rule::in(self::METHODS)],
            'fecha_pago'=>['required','date','before_or_equal:today'],
            'referencia'=>['nullable','string','max:180'],
            'observaciones'=>['nullable','string','max:1000'],
            'comprobante'=>['nullable','file','max:5120','mimes:jpg,jpeg,png,webp,pdf'],
        ],[
            'comprobante.max'=>'El comprobante no puede superar 5 MB.',
            'comprobante.mimes'=>'El comprobante debe ser una imagen JPG/PNG/WEBP o un PDF.',
        ]);

        abort_if((float)$data['monto'] > $expected + 0.001,422,'El monto indicado supera el importe pendiente de '.number_format($expected,2).' Bs.');
        if ($data['metodo'] !== 'efectivo') {
            abort_unless($request->hasFile('comprobante'),422,'Adjunta el comprobante del pago realizado.');
        }
        return $data;
    }

    private function projectDue(Proyecto $project): array
    {
        $confirmed = $project->pagos()->where('estado_revision','confirmado')->get();
        $initialTarget = (float)($project->anticipo_monto ?? 0);
        $initialPaid = (float)$confirmed->where('tipo','anticipo')->sum('monto');
        if ($initialPaid + 0.001 < $initialTarget) {
            return ['anticipo',round($initialTarget-$initialPaid,2)];
        }

        $totalPaid = (float)$confirmed->sum('monto');
        return ['saldo_final',max(0,round((float)$project->precio_acordado-$totalPaid,2))];
    }

    private function subscriptionDue(Suscripcion $subscription): float
    {
        if (!$subscription->primer_cobro_pagado && $subscription->primer_cobro_monto !== null) {
            return (float)$subscription->primer_cobro_monto;
        }
        return (float)$subscription->monto;
    }

    private function storeProof(Request $request, string $folder, string $method): array
    {
        if (!$request->hasFile('comprobante')) {
            return ['path'=>null,'name'=>null,'mime'=>null];
        }
        $file = $request->file('comprobante');
        $path = $file->store('pagos/comprobantes/'.$folder,'private_uploads');
        return ['path'=>$path,'name'=>$file->getClientOriginalName(),'mime'=>$file->getMimeType()];
    }

    private function streamProof(?string $path, ?string $name, ?string $mime): StreamedResponse
    {
        abort_unless($path && Storage::disk('private_uploads')->exists($path),404,'El comprobante ya no está disponible.');
        $stream = Storage::disk('private_uploads')->readStream($path);
        abort_unless(is_resource($stream),404,'No pudimos abrir el comprobante.');
        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        },$name ?: 'comprobante',["Content-Type"=>$mime ?: 'application/octet-stream','Cache-Control'=>'private, no-store']);
    }
}
