<?php

namespace App\Http\Controllers;

use App\Models\{ConfiguracionPago,Proyecto};
use App\Services\{FeatureGateService,SubscriptionAccessService,TenantContext};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientBillingController extends Controller
{
    public function index(Request $request, SubscriptionAccessService $access, TenantContext $tenants, FeatureGateService $features): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        $empresa->loadMissing('planViti');

        $projects = $this->projectRows($empresa->id, $access);
        $config = ConfiguracionPago::query()->where('activo',true)->first();
        return response()->json(['data'=>[
            'negocio'=>[
                'id'=>$empresa->id,
                'nombre_comercial'=>$empresa->nombre_comercial,
                'metodo_pago_preferido'=>$empresa->metodo_pago_preferido ?: 'qr',
            ],
            'plan'=> $this->planRow($empresa->planViti),
            'uso'=>$features->snapshot($empresa),
            'configuracion'=>$config ? $this->configRow($config) : null,
            'proyectos'=>$projects,
        ]]);
    }

    public function plan(Request $request, FeatureGateService $features, SubscriptionAccessService $access, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        $empresa->loadMissing('planViti');

        $subscriptions = $empresa->aplicaciones()
            ->with('suscripcion')
            ->whereHas('suscripcion')
            ->get()
            ->map(fn ($app) => [
                'aplicacion_id'=>$app->id,
                'aplicacion'=>$app->nombre,
                'suscripcion'=>$access->statusFor($app),
            ])
            ->values();

        return response()->json(['data'=>[
            'negocio'=>['id'=>$empresa->id,'nombre_comercial'=>$empresa->nombre_comercial],
            'plan'=>$this->planRow($empresa->planViti),
            'uso'=>$features->snapshot($empresa),
            'suscripciones'=>$subscriptions,
        ]]);
    }

    private function planRow($plan): ?array
    {
        if (!$plan) return null;
        return [
            'id'=>$plan->id,'codigo'=>$plan->codigo,'nombre'=>$plan->nombre,'descripcion'=>$plan->descripcion,
            'precio_mensual'=>$plan->precio_mensual !== null ? (float)$plan->precio_mensual : null,
            'precio_anual'=>$plan->precio_anual !== null ? (float)$plan->precio_anual : null,
            'dias_prueba'=>$plan->dias_prueba,'modulos'=>$plan->modulos,
            'max_usuarios'=>$plan->max_usuarios,'max_aplicaciones'=>$plan->max_aplicaciones,
        ];
    }

    private function projectRows(int $empresaId, SubscriptionAccessService $access)
    {
        return Proyecto::query()
            ->where('empresa_id',$empresaId)
            ->with([
                'empresa:id,nombre_comercial,metodo_pago_preferido',
                'aplicacion'=>fn($q)=>$q->with('suscripcion.pagos.pagador:id,nombre,apellido,usuario,telefono'),
                'pagos.pagador:id,nombre,apellido,usuario,telefono',
            ])
            ->latest('id')->get()
            ->map(function (Proyecto $project) use ($access): array {
                $confirmed = $project->pagos->where('estado_revision','confirmado');
                $initialPaid = (float)$confirmed->where('tipo','anticipo')->sum('monto');
                $balancePaid = (float)$confirmed->whereIn('tipo',['saldo_final','otro'])->sum('monto');
                $paid = round($initialPaid+$balancePaid,2);
                $total = (float)($project->precio_acordado ?? 0);
                $initialTarget=(float)($project->anticipo_monto??0);
                $nextType=$initialPaid+0.001<$initialTarget?'anticipo':'saldo_final';
                $nextAmount=$nextType==='anticipo'
                    ? max(0,round($initialTarget-$initialPaid,2))
                    : max(0,round($total-$paid,2));
                $pendingProjectProof=$project->pagos->firstWhere('estado_revision','pendiente_revision');

                $app = $project->aplicacion;
                $subscription = $app ? $access->statusFor($app) : null;
                $subscriptionModel=$app?->suscripcion;
                $pendingSubscriptionProof=$subscriptionModel?->pagos?->firstWhere('estado_revision','pendiente_revision');
                $subscriptionDue = null;
                if ($subscriptionModel) {
                    $subscriptionDue = !$subscriptionModel->primer_cobro_pagado && $subscriptionModel->primer_cobro_monto !== null
                        ? (float)$subscriptionModel->primer_cobro_monto
                        : (float)$subscriptionModel->monto;
                }

                return [
                    'id'=>$project->id,'codigo'=>$project->codigo,'nombre'=>$project->nombre,
                    'empresa'=>[
                        'id'=>$project->empresa?->id,
                        'nombre_comercial'=>$project->empresa?->nombre_comercial,
                        'metodo_pago_preferido'=>$project->empresa?->metodo_pago_preferido ?: 'qr',
                    ],
                    'precio_acordado'=>$project->precio_acordado !== null ? (float)$project->precio_acordado : null,
                    'anticipo_monto'=>$project->anticipo_monto !== null ? (float)$project->anticipo_monto : null,
                    'saldo_monto'=>$project->saldo_monto !== null ? (float)$project->saldo_monto : null,
                    'estado_pago'=>$project->estado_pago,
                    'pagado'=>$paid,
                    'pendiente'=>max(0,round($total-$paid,2)),
                    'siguiente_pago'=>[
                        'tipo'=>$nextType,
                        'monto'=>$nextAmount,
                        'puede_enviar'=>$project->precio_acordado !== null && $nextAmount>0 && !$pendingProjectProof,
                        'comprobante_pendiente_id'=>$pendingProjectProof?->id,
                    ],
                    'pagos'=>$project->pagos->values(),
                    'aplicacion'=>$app ? ['id'=>$app->id,'nombre'=>$app->nombre,'estado'=>$app->estado,'acceso_cliente'=>(bool)$app->acceso_cliente] : null,
                    'suscripcion'=>$subscription ? [
                        ...$subscription,
                        'importe_pendiente'=>$subscriptionDue,
                        'puede_enviar_comprobante'=>!($subscription['en_prueba']??false)
                            && ($subscription['estado']??null)!=='cancelada'
                            && $subscriptionDue>0
                            && !$pendingSubscriptionProof,
                        'comprobante_pendiente_id'=>$pendingSubscriptionProof?->id,
                    ] : null,
                    'pagos_suscripcion'=>$subscriptionModel?->pagos?->values() ?? [],
                ];
            })->values();
    }

    private function configRow(ConfiguracionPago $config): array
    {
        $path = ltrim((string)$config->qr_path,'/');
        $qrUrl = url('/viti-payment-qr.svg');
        if ($path && Storage::disk('public')->exists($path)) $qrUrl = Storage::disk('public')->url($path);
        return ['id'=>$config->id,'nombre'=>$config->nombre,'banco'=>$config->banco,'titular'=>$config->titular,'moneda'=>$config->moneda,'qr_path'=>$config->qr_path,'qr_url'=>$qrUrl,'observaciones'=>$config->observaciones];
    }
}
