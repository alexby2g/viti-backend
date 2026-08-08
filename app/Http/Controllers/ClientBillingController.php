<?php

namespace App\Http\Controllers;

use App\Models\{ConfiguracionPago,Proyecto};
use App\Services\SubscriptionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientBillingController extends Controller
{
    public function index(Request $request, SubscriptionAccessService $access): JsonResponse
    {
        $clienteId = (int)$request->user()->cliente_id;

        $projects = Proyecto::query()
            ->where('cliente_id',$clienteId)
            ->with([
                'empresa:id,nombre_comercial',
                'aplicacion'=>fn($q)=>$q->with('suscripcion.pagos'),
                'pagos',
            ])
            ->latest('id')
            ->get()
            ->map(function (Proyecto $project) use ($access): array {
                $initialPaid = (float)$project->pagos->where('tipo','anticipo')->sum('monto');
                $balancePaid = (float)$project->pagos->whereIn('tipo',['saldo_final','otro'])->sum('monto');
                $paid = round($initialPaid+$balancePaid,2);
                $total = (float)($project->precio_acordado ?? 0);
                $app = $project->aplicacion;
                $subscription = $app ? $access->statusFor($app) : null;

                return [
                    'id'=>$project->id,
                    'codigo'=>$project->codigo,
                    'nombre'=>$project->nombre,
                    'empresa'=>$project->empresa,
                    'precio_acordado'=>$project->precio_acordado !== null ? (float)$project->precio_acordado : null,
                    'anticipo_monto'=>$project->anticipo_monto !== null ? (float)$project->anticipo_monto : null,
                    'saldo_monto'=>$project->saldo_monto !== null ? (float)$project->saldo_monto : null,
                    'estado_pago'=>$project->estado_pago,
                    'pagado'=>$paid,
                    'pendiente'=>max(0,round($total-$paid,2)),
                    'pagos'=>$project->pagos->values(),
                    'aplicacion'=>$app ? ['id'=>$app->id,'nombre'=>$app->nombre,'estado'=>$app->estado,'acceso_cliente'=>(bool)$app->acceso_cliente] : null,
                    'suscripcion'=>$subscription,
                    'pagos_suscripcion'=>$app?->suscripcion?->pagos?->values() ?? [],
                ];
            });

        $config = ConfiguracionPago::query()->where('activo',true)->first();

        return response()->json(['data'=>[
            'configuracion'=>$config ? $this->configRow($config) : null,
            'proyectos'=>$projects->values(),
        ]]);
    }

    private function configRow(ConfiguracionPago $config): array
    {
        $path = ltrim((string)$config->qr_path,'/');
        $qrUrl = null;
        if ($path && Storage::disk('public')->exists($path)) {
            $qrUrl = Storage::disk('public')->url($path);
        }

        return [
            'id'=>$config->id,
            'nombre'=>$config->nombre,
            'banco'=>$config->banco,
            'titular'=>$config->titular,
            'moneda'=>$config->moneda,
            'qr_path'=>$config->qr_path,
            'qr_url'=>$qrUrl,
            'observaciones'=>$config->observaciones,
        ];
    }
}
