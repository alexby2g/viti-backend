<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,ConfiguracionPago,Proyecto,ProyectoPago,Suscripcion,SuscripcionPago};
use App\Services\SubscriptionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function index(SubscriptionAccessService $access): JsonResponse
    {
        $projects = Proyecto::query()
            ->with(['empresa:id,nombre_comercial','cliente:id,nombre,telefono','aplicacion:id,proyecto_id,nombre,estado,acceso_cliente','pagos'])
            ->latest('id')->limit(150)->get()
            ->map(fn (Proyecto $p) => $this->projectRow($p));

        $subscriptions = Suscripcion::query()
            ->with(['aplicacion:id,empresa_id,proyecto_id,nombre,estado,acceso_cliente','empresa:id,nombre_comercial','pagos'])
            ->latest('id')->get()
            ->map(function (Suscripcion $s) use ($access): array {
                $s = $access->refresh($s);
                return $this->subscriptionRow($s);
            });

        $projectPending = $projects->sum('pendiente');
        $monthlyRecurring = $subscriptions->where('estado','!=','cancelada')->sum(function (array $s): float {
            return $s['frecuencia'] === 'anual' ? round($s['monto'] / 12, 2) : $s['monto'];
        });

        $config = ConfiguracionPago::query()->where('activo',true)->first();

        return response()->json(['data'=>[
            'resumen'=>[
                'por_cobrar_proyectos'=>round($projectPending,2),
                'suscripciones_activas'=>$subscriptions->where('estado','activa')->count(),
                'suscripciones_gracia'=>$subscriptions->where('estado','gracia')->count(),
                'suscripciones_suspendidas'=>$subscriptions->where('estado','suspendida')->count(),
                'ingreso_recurrente_mensual'=>round($monthlyRecurring,2),
            ],
            'configuracion'=>$config ? $this->configRow($config) : null,
            'proyectos'=>$projects->values(),
            'suscripciones'=>$subscriptions->values(),
        ]]);
    }

    public function guardarAcuerdo(Request $request, Proyecto $proyecto): JsonResponse
    {
        $data = $request->validate([
            'precio_acordado'=>['required','numeric','min:0.01'],
            'anticipo_monto'=>['required','numeric','min:0'],
            'observaciones'=>['nullable','string','max:3000'],
        ]);

        $total = round((float)$data['precio_acordado'],2);
        $initial = round((float)$data['anticipo_monto'],2);
        abort_if($initial > $total,422,'El anticipo no puede ser mayor al precio acordado.');
        $balance = round($total - $initial,2);

        $proyecto->update([
            'precio_acordado'=>$total,
            'anticipo_monto'=>$initial,
            'saldo_monto'=>$balance,
            'estado_pago'=>'pendiente_anticipo',
            'observaciones'=>$data['observaciones'] ?? $proyecto->observaciones,
        ]);

        $this->refreshProjectPaymentStatus($proyecto);
        return response()->json(['data'=>$this->projectRow($proyecto->fresh()->load(['empresa','cliente','aplicacion','pagos']))]);
    }

    public function registrarPagoProyecto(Request $request, Proyecto $proyecto): JsonResponse
    {
        abort_unless($proyecto->precio_acordado !== null,422,'Primero registra el acuerdo económico del proyecto.');
        $data = $request->validate([
            'tipo'=>['required',Rule::in(['anticipo','saldo_final','otro'])],
            'monto'=>['required','numeric','min:0.01'],
            'metodo'=>['required',Rule::in(['qr','transferencia','efectivo','otro'])],
            'fecha_pago'=>['required','date'],
            'referencia'=>['nullable','string','max:180'],
            'observaciones'=>['nullable','string','max:3000'],
        ]);

        ProyectoPago::create([
            ...$data,
            'proyecto_id'=>$proyecto->id,
            'empresa_id'=>$proyecto->empresa_id,
            'registrado_por'=>$request->user()?->id,
        ]);

        $this->refreshProjectPaymentStatus($proyecto);
        return response()->json(['data'=>$this->projectRow($proyecto->fresh()->load(['empresa','cliente','aplicacion','pagos']))],201);
    }

    public function guardarSuscripcion(Request $request, Aplicacion $aplicacion, SubscriptionAccessService $access): JsonResponse
    {
        abort_unless($aplicacion->empresa_id,422,'La aplicación debe pertenecer a una empresa.');
        $data = $request->validate([
            'plan'=>['required','string','max:80'],
            'monto'=>['required','numeric','min:0.01'],
            'frecuencia'=>['required',Rule::in(['mensual','anual'])],
            'fecha_inicio'=>['required','date'],
            'fecha_vencimiento'=>['nullable','date','after_or_equal:fecha_inicio'],
            'dias_gracia'=>['required','integer','min:0','max:60'],
            'estado'=>['nullable',Rule::in(['activa','gracia','suspendida','cancelada'])],
        ]);

        $start = Carbon::parse($data['fecha_inicio'])->startOfDay();
        $due = !empty($data['fecha_vencimiento'])
            ? Carbon::parse($data['fecha_vencimiento'])->startOfDay()
            : ($data['frecuencia'] === 'anual' ? $start->copy()->addYear() : $start->copy()->addMonth());

        $subscription = Suscripcion::updateOrCreate(
            ['aplicacion_id'=>$aplicacion->id],
            [
                'empresa_id'=>$aplicacion->empresa_id,
                'plan'=>$data['plan'],
                'monto'=>$data['monto'],
                'frecuencia'=>$data['frecuencia'],
                'moneda'=>'BOB',
                'fecha_inicio'=>$start->toDateString(),
                'fecha_vencimiento'=>$due->toDateString(),
                'dias_gracia'=>$data['dias_gracia'],
                'estado'=>$data['estado'] ?? 'activa',
            ]
        );

        $subscription = $access->refresh($subscription->fresh());
        return response()->json(['data'=>$this->subscriptionRow($subscription->load(['aplicacion','empresa','pagos']))]);
    }

    public function registrarPagoSuscripcion(Request $request, Suscripcion $suscripcion, SubscriptionAccessService $access): JsonResponse
    {
        $data = $request->validate([
            'monto'=>['required','numeric','min:0.01'],
            'metodo'=>['required',Rule::in(['qr','transferencia','efectivo','otro'])],
            'fecha_pago'=>['required','date'],
            'referencia'=>['nullable','string','max:180'],
            'observaciones'=>['nullable','string','max:3000'],
        ]);

        DB::transaction(function () use ($request,$suscripcion,$data): void {
            SuscripcionPago::create([
                ...$data,
                'suscripcion_id'=>$suscripcion->id,
                'registrado_por'=>$request->user()?->id,
            ]);

            $base = $suscripcion->fecha_vencimiento && $suscripcion->fecha_vencimiento->isFuture()
                ? $suscripcion->fecha_vencimiento->copy()
                : Carbon::parse($data['fecha_pago']);
            $next = $suscripcion->frecuencia === 'anual' ? $base->addYear() : $base->addMonth();
            $suscripcion->update(['fecha_vencimiento'=>$next->toDateString(),'estado'=>'activa']);
        });

        $suscripcion = $access->refresh($suscripcion->fresh());
        return response()->json(['data'=>$this->subscriptionRow($suscripcion->load(['aplicacion','empresa','pagos']))],201);
    }

    public function actualizarConfiguracion(Request $request): JsonResponse
    {
        $config = ConfiguracionPago::query()->where('activo',true)->first();
        if (!$config) {
            $config = ConfiguracionPago::create([
                'nombre'=>'QR principal VITI',
                'moneda'=>'BOB',
                'activo'=>true,
            ]);
        }

        $data = $request->validate([
            'banco'=>['required','string','max:120'],
            'titular'=>['required','string','max:180'],
            'observaciones'=>['nullable','string','max:3000'],
        ]);
        $config->update($data);
        return response()->json(['data'=>$this->configRow($config->fresh())]);
    }

    public function subirQr(Request $request): JsonResponse
    {
        $request->validate([
            'qr'=>['required','image','mimes:jpg,jpeg,png,webp','max:5120'],
        ], [
            'qr.required'=>'Selecciona una imagen de QR.',
            'qr.image'=>'El archivo debe ser una imagen válida.',
            'qr.mimes'=>'El QR debe ser JPG, PNG o WEBP.',
            'qr.max'=>'La imagen del QR no puede superar 5 MB.',
        ]);

        $config = ConfiguracionPago::query()->where('activo',true)->first();
        if (!$config) {
            $config = ConfiguracionPago::create([
                'nombre'=>'QR principal VITI',
                'moneda'=>'BOB',
                'activo'=>true,
            ]);
        }

        $old = ltrim((string)$config->qr_path,'/');
        if ($old && Storage::disk('public')->exists($old)) {
            Storage::disk('public')->delete($old);
        }

        $path = $request->file('qr')->store('pagos/qr','public');
        $config->update(['qr_path'=>$path]);

        return response()->json([
            'data'=>$this->configRow($config->fresh()),
            'message'=>'QR de cobro actualizado.',
        ]);
    }

    private function refreshProjectPaymentStatus(Proyecto $project): void
    {
        $project->loadMissing('pagos');
        if ($project->precio_acordado === null) {
            $project->update(['estado_pago'=>'sin_acuerdo']);
            return;
        }

        $initialTarget = (float)($project->anticipo_monto ?? 0);
        $balanceTarget = (float)($project->saldo_monto ?? 0);
        $initialPaid = (float)$project->pagos->where('tipo','anticipo')->sum('monto');
        $balancePaid = (float)$project->pagos->whereIn('tipo',['saldo_final','otro'])->sum('monto');

        $status = 'pendiente_anticipo';
        if ($initialPaid + 0.001 >= $initialTarget) $status = 'pendiente_saldo';
        if (($initialPaid + $balancePaid) + 0.001 >= (float)$project->precio_acordado || ($initialPaid + 0.001 >= $initialTarget && $balancePaid + 0.001 >= $balanceTarget)) $status = 'pagado';
        $project->update(['estado_pago'=>$status]);
    }

    private function projectRow(Proyecto $p): array
    {
        $initialPaid = (float)$p->pagos->where('tipo','anticipo')->sum('monto');
        $balancePaid = (float)$p->pagos->whereIn('tipo',['saldo_final','otro'])->sum('monto');
        $paid = round($initialPaid + $balancePaid,2);
        $total = (float)($p->precio_acordado ?? 0);
        return [
            'id'=>$p->id,'codigo'=>$p->codigo,'nombre'=>$p->nombre,'empresa'=>$p->empresa,'cliente'=>$p->cliente,'aplicacion'=>$p->aplicacion,
            'precio_acordado'=>$p->precio_acordado !== null ? (float)$p->precio_acordado : null,
            'anticipo_monto'=>$p->anticipo_monto !== null ? (float)$p->anticipo_monto : null,
            'saldo_monto'=>$p->saldo_monto !== null ? (float)$p->saldo_monto : null,
            'estado_pago'=>$p->estado_pago,
            'pagado'=>$paid,'pendiente'=>max(0,round($total-$paid,2)),
            'pagos'=>$p->pagos->values(),
        ];
    }

    private function subscriptionRow(Suscripcion $s): array
    {
        return [
            'id'=>$s->id,'aplicacion'=>$s->aplicacion,'empresa'=>$s->empresa,'plan'=>$s->plan,'monto'=>(float)$s->monto,
            'frecuencia'=>$s->frecuencia,'moneda'=>$s->moneda,'fecha_inicio'=>$s->fecha_inicio?->format('Y-m-d'),
            'fecha_vencimiento'=>$s->fecha_vencimiento?->format('Y-m-d'),'dias_gracia'=>(int)$s->dias_gracia,'estado'=>$s->estado,
            'pagos'=>$s->pagos->values(),
        ];
    }

    private function configRow(ConfiguracionPago $config): array
    {
        $path = ltrim((string)$config->qr_path,'/');
        $qrUrl = url('/viti-payment-qr.svg');
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
            'activo'=>(bool)$config->activo,
            'observaciones'=>$config->observaciones,
        ];
    }
}
