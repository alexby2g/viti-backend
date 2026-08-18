<?php

namespace App\Http\Controllers;

use App\Models\{Proyecto,ProyectoPago,Suscripcion,SuscripcionPago};
use App\Services\{SubscriptionAccessService,TenantContext};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ClientPaymentController extends Controller
{
    private const METHODS = ['qr','transferencia','efectivo','otro'];

    public function proyecto(Request $request, Proyecto $proyecto, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        abort_unless((int)$proyecto->empresa_id === (int)$empresa->id,403,'Este proyecto no pertenece a tu negocio.');
        abort_unless($proyecto->precio_acordado !== null,422,'El proyecto todavía no tiene un precio acordado.');

        $pendingExisting = ProyectoPago::query()->where('proyecto_id',$proyecto->id)->where('estado_revision','pendiente_revision')->exists();
        abort_if($pendingExisting,422,'Ya existe un comprobante de este proyecto pendiente de revisión.');

        [$type,$expected] = $this->projectDue($proyecto);
        abort_if($expected <= 0,422,'Este proyecto no tiene un pago pendiente.');
        $data = $this->validateSubmission($request,$expected,false);
        $proof = $this->storeProof($request,'proyectos/'.$proyecto->id);

        try {
            $payment = DB::transaction(function () use ($request,$proyecto,$empresa,$data,$proof): ProyectoPago {
                $lockedProject = Proyecto::query()->lockForUpdate()->findOrFail($proyecto->id);
                abort_unless((int)$lockedProject->empresa_id === (int)$empresa->id,403,'Este proyecto no pertenece a tu negocio.');
                abort_unless($lockedProject->precio_acordado !== null,422,'El proyecto todavía no tiene un precio acordado.');
                abort_if(ProyectoPago::query()->where('proyecto_id',$lockedProject->id)->where('estado_revision','pendiente_revision')->exists(),422,'Ya existe un comprobante de este proyecto pendiente de revisión.');
                [$type,$currentExpected] = $this->projectDue($lockedProject);
                abort_if($currentExpected <= 0,422,'Este proyecto no tiene un pago pendiente.');
                $this->assertAmount((float)$data['monto'],$currentExpected,false);

                return ProyectoPago::create([
                    'proyecto_id'=>$lockedProject->id,'empresa_id'=>$empresa->id,'pagador_usuario_id'=>$request->user()->id,
                    'tipo'=>$type,'monto'=>$data['monto'],'metodo'=>$data['metodo'],'fecha_pago'=>$data['fecha_pago'],
                    'referencia'=>$data['referencia'] ?? null,'observaciones'=>$data['observaciones'] ?? null,
                    'comprobante_path'=>$proof['path'],'comprobante_nombre'=>$proof['name'],'comprobante_mime'=>$proof['mime'],
                    'registrado_por'=>$request->user()->id,'estado_revision'=>'pendiente_revision','origen'=>'cliente','enviado_at'=>now(),
                ]);
            },3);
        } catch (Throwable $e) { $this->deleteProof($proof['path']); throw $e; }

        return response()->json(['message'=>'Comprobante enviado. El pago quedará aplicado cuando VITI lo confirme.','data'=>$payment->load('pagador:id,nombre,apellido,usuario')],201);
    }

    public function suscripcion(Request $request, Suscripcion $suscripcion, TenantContext $tenants, SubscriptionAccessService $access): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        abort_unless((int)$suscripcion->empresa_id === (int)$empresa->id,403,'Esta suscripción no pertenece a tu negocio.');

        $status = $access->statusFor($suscripcion->aplicacion);
        abort_if($status['en_prueba'] ?? false,422,'Todavía estás dentro del periodo de prueba gratuita. El cobro comenzará después del '.$status['prueba_hasta'].'.');
        abort_if($suscripcion->estado === 'cancelada',422,'Esta suscripción está cancelada.');
        abort_if(SuscripcionPago::query()->where('suscripcion_id',$suscripcion->id)->where('estado_revision','pendiente_revision')->exists(),422,'Ya existe un comprobante de suscripción pendiente de revisión.');

        $expected = $this->subscriptionDue($suscripcion);
        abort_if($expected <= 0,422,'Esta suscripción no tiene un pago pendiente.');
        $exactAmount = $this->requiresExactSubscriptionPayment($suscripcion);
        $data = $this->validateSubmission($request,$expected,$exactAmount);
        $proof = $this->storeProof($request,'suscripciones/'.$suscripcion->id);

        try {
            $payment = DB::transaction(function () use ($request,$suscripcion,$empresa,$access,$data,$proof): SuscripcionPago {
                $lockedSubscription = Suscripcion::query()->lockForUpdate()->findOrFail($suscripcion->id);
                abort_unless((int)$lockedSubscription->empresa_id === (int)$empresa->id,403,'Esta suscripción no pertenece a tu negocio.');
                abort_if($lockedSubscription->estado === 'cancelada',422,'Esta suscripción está cancelada.');
                $lockedStatus = $access->statusFor($lockedSubscription->aplicacion);
                abort_if($lockedStatus['en_prueba'] ?? false,422,'Todavía estás dentro del periodo de prueba gratuita. El cobro comenzará después del '.$lockedStatus['prueba_hasta'].'.');
                abort_if(SuscripcionPago::query()->where('suscripcion_id',$lockedSubscription->id)->where('estado_revision','pendiente_revision')->exists(),422,'Ya existe un comprobante de suscripción pendiente de revisión.');

                $currentExpected = $this->subscriptionDue($lockedSubscription);
                abort_if($currentExpected <= 0,422,'Esta suscripción no tiene un pago pendiente.');
                $this->assertAmount((float)$data['monto'],$currentExpected,$this->requiresExactSubscriptionPayment($lockedSubscription));

                return SuscripcionPago::create([
                    'suscripcion_id'=>$lockedSubscription->id,'empresa_id'=>$empresa->id,'pagador_usuario_id'=>$request->user()->id,
                    'monto'=>$data['monto'],'metodo'=>$data['metodo'],'fecha_pago'=>$data['fecha_pago'],
                    'referencia'=>$data['referencia'] ?? null,'observaciones'=>$data['observaciones'] ?? null,
                    'comprobante_path'=>$proof['path'],'comprobante_nombre'=>$proof['name'],'comprobante_mime'=>$proof['mime'],
                    'registrado_por'=>$request->user()->id,'estado_revision'=>'pendiente_revision','origen'=>'cliente','enviado_at'=>now(),
                ]);
            },3);
        } catch (Throwable $e) { $this->deleteProof($proof['path']); throw $e; }

        return response()->json(['message'=>'Comprobante de suscripción enviado. Se aplicará cuando VITI lo confirme.','data'=>$payment->load('pagador:id,nombre,apellido,usuario')],201);
    }

    public function comprobanteProyecto(Request $request, ProyectoPago $pago, TenantContext $tenants): StreamedResponse
    {
        $empresa = $tenants->resolve($request); $tenants->assertCanManage($request->user(),$empresa);
        abort_unless((int)$pago->empresa_id === (int)$empresa->id,403,'No tienes permiso para abrir este comprobante.');
        return $this->streamProof($pago->comprobante_path,$pago->comprobante_nombre,$pago->comprobante_mime);
    }

    public function comprobanteSuscripcion(Request $request, SuscripcionPago $pago, TenantContext $tenants): StreamedResponse
    {
        $empresa = $tenants->resolve($request); $tenants->assertCanManage($request->user(),$empresa);
        abort_unless((int)$pago->empresa_id === (int)$empresa->id,403,'No tienes permiso para abrir este comprobante.');
        return $this->streamProof($pago->comprobante_path,$pago->comprobante_nombre,$pago->comprobante_mime);
    }

    private function validateSubmission(Request $request, float $expected, bool $exactAmount): array
    {
        $data = $request->validate([
            'monto'=>['required','numeric','min:0.01'],'metodo'=>['required',Rule::in(self::METHODS)],
            'fecha_pago'=>['required','date','before_or_equal:today'],'referencia'=>['nullable','string','max:180'],
            'observaciones'=>['nullable','string','max:1000'],'comprobante'=>['nullable','file','max:5120','mimes:jpg,jpeg,png,webp,pdf'],
        ],['comprobante.max'=>'El comprobante no puede superar 5 MB.','comprobante.mimes'=>'El comprobante debe ser una imagen JPG/PNG/WEBP o un PDF.']);
        $this->assertAmount((float)$data['monto'],$expected,$exactAmount);
        if ($data['metodo'] !== 'efectivo') abort_unless($request->hasFile('comprobante'),422,'Adjunta el comprobante del pago realizado.');
        return $data;
    }

    private function assertAmount(float $amount, float $expected, bool $exactAmount): void
    {
        if($exactAmount) abort_if(abs($amount-$expected)>0.001,422,'La suscripción requiere el monto exacto de '.number_format($expected,2).' Bs.');
        else abort_if($amount > $expected + 0.001,422,'El monto indicado supera el importe pendiente de '.number_format($expected,2).' Bs.');
    }

    private function projectDue(Proyecto $project): array
    {
        $confirmed = $project->pagos()->where('estado_revision','confirmado')->get();
        $initialTarget = (float)($project->anticipo_monto ?? 0);
        $initialPaid = (float)$confirmed->where('tipo','anticipo')->sum('monto');
        if ($initialPaid + 0.001 < $initialTarget) return ['anticipo',round($initialTarget-$initialPaid,2)];
        $totalPaid = (float)$confirmed->sum('monto');
        return ['saldo_final',max(0,round((float)$project->precio_acordado-$totalPaid,2))];
    }

    private function subscriptionDue(Suscripcion $subscription): float
    {
        if (!$subscription->primer_cobro_pagado && $subscription->primer_cobro_monto !== null) {
            $confirmedFirstPayment = (float)$subscription->pagos()->where('estado_revision','confirmado')->sum('monto');
            return max(0,round((float)$subscription->primer_cobro_monto-$confirmedFirstPayment,2));
        }
        return (float)$subscription->monto;
    }

    private function requiresExactSubscriptionPayment(Suscripcion $subscription): bool
    {
        return $subscription->primer_cobro_pagado || $subscription->primer_cobro_monto === null;
    }

    private function storeProof(Request $request, string $folder): array
    {
        if (!$request->hasFile('comprobante')) return ['path'=>null,'name'=>null,'mime'=>null];
        $file = $request->file('comprobante'); $path = $file->store('pagos/comprobantes/'.$folder,'private_uploads');
        abort_unless($path,503,'No pudimos almacenar el comprobante. Inténtalo nuevamente.');
        return ['path'=>$path,'name'=>$file->getClientOriginalName(),'mime'=>$file->getMimeType()];
    }

    private function deleteProof(?string $path): void
    {
        if (!$path) return;
        try { if (Storage::disk('private_uploads')->exists($path)) Storage::disk('private_uploads')->delete($path); } catch (Throwable $e) { report($e); }
    }

    private function streamProof(?string $path, ?string $name, ?string $mime): StreamedResponse
    {
        abort_unless($path && Storage::disk('private_uploads')->exists($path),404,'El comprobante ya no está disponible.');
        $stream = Storage::disk('private_uploads')->readStream($path);
        abort_unless(is_resource($stream),404,'No pudimos abrir el comprobante.');
        return response()->streamDownload(function () use ($stream): void { fpassthru($stream); fclose($stream); },$name ?: 'comprobante',["Content-Type"=>$mime ?: 'application/octet-stream','Cache-Control'=>'private, no-store']);
    }
}
