<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,ConfiguracionPago,Empresa,Proyecto,ProyectoPago,Suscripcion,SuscripcionPago};
use App\Services\SubscriptionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    private const PAYMENT_METHODS = ['qr','transferencia','efectivo','otro'];

    public function index(SubscriptionAccessService $access): JsonResponse
    {
        $companyWithAccounts = fn ($q) => $q
            ->select('id','nombre_comercial','metodo_pago_preferido')
            ->with(['usuarios' => fn ($users) => $users
                ->wherePivot('activo',true)
                ->select('usuarios.id','usuarios.nombre','usuarios.apellido','usuarios.usuario','usuarios.telefono')
                ->orderBy('usuarios.nombre')]);

        $projects = Proyecto::query()
            ->with([
                'empresa'=>$companyWithAccounts,
                'cliente:id,nombre,telefono',
                'aplicacion:id,proyecto_id,nombre,estado,acceso_cliente',
                'pagos.pagador:id,nombre,apellido,usuario,telefono',
                'pagos.revisor:id,nombre,apellido,usuario',
            ])
            ->latest('id')->limit(150)->get()
            ->map(fn (Proyecto $p) => $this->projectRow($p));

        $subscriptions = Suscripcion::query()
            ->with([
                'aplicacion:id,empresa_id,proyecto_id,nombre,estado,acceso_cliente',
                'empresa'=>$companyWithAccounts,
                'pagos.pagador:id,nombre,apellido,usuario,telefono',
                'pagos.revisor:id,nombre,apellido,usuario',
            ])
            ->latest('id')->get()
            ->map(function (Suscripcion $s) use ($access): array {
                $s = $access->refresh($s);
                return $this->subscriptionRow($s);
            });

        $projectPending = $projects->sum('pendiente');
        $monthlyRecurring = $subscriptions->where('estado','!=','cancelada')->sum(function (array $s): float {
            return $s['frecuencia'] === 'anual' ? round($s['monto'] / 12, 2) : $s['monto'];
        });
        $proofsPending = $projects->sum(fn(array $p)=>collect($p['pagos'])->where('estado_revision','pendiente_revision')->count())
            + $subscriptions->sum(fn(array $s)=>collect($s['pagos'])->where('estado_revision','pendiente_revision')->count());

        $config = ConfiguracionPago::query()->where('activo',true)->first();

        return response()->json(['data'=>[
            'resumen'=>[
                'por_cobrar_proyectos'=>round($projectPending,2),
                'suscripciones_activas'=>$subscriptions->where('estado','activa')->count(),
                'suscripciones_gracia'=>$subscriptions->where('estado','gracia')->count(),
                'suscripciones_suspendidas'=>$subscriptions->where('estado','suspendida')->count(),
                'ingreso_recurrente_mensual'=>round($monthlyRecurring,2),
                'comprobantes_pendientes'=>$proofsPending,
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
        return response()->json(['data'=>$this->projectRow($this->reloadProject($proyecto))]);
    }

    public function registrarPagoProyecto(Request $request, Proyecto $proyecto): JsonResponse
    {
        abort_unless($proyecto->precio_acordado !== null,422,'Primero registra el acuerdo económico del proyecto.');
        abort_unless($proyecto->empresa_id,422,'El proyecto debe estar asociado a una empresa antes de registrar pagos.');
        abort_if($proyecto->pagos()->where('estado_revision','pendiente_revision')->exists(),422,'Hay un comprobante del cliente pendiente de revisión. Confírmalo o recházalo antes de registrar otro pago.');
        $data = $request->validate([
            'tipo'=>['required',Rule::in(['anticipo','saldo_final','otro'])],
            'monto'=>['required','numeric','min:0.01'],
            'metodo'=>['required',Rule::in(self::PAYMENT_METHODS)],
            'fecha_pago'=>['required','date'],
            'referencia'=>['nullable','string','max:180'],
            'observaciones'=>['nullable','string','max:3000'],
            'pagador_usuario_id'=>['nullable','integer','exists:usuarios,id'],
        ]);

        $empresa = Empresa::findOrFail($proyecto->empresa_id);
        $this->assertPayerBelongsToCompany($empresa,$data['pagador_usuario_id'] ?? null);

        ProyectoPago::create([
            ...$data,
            'proyecto_id'=>$proyecto->id,
            'empresa_id'=>$empresa->id,
            'registrado_por'=>$request->user()?->id,
            'estado_revision'=>'confirmado',
            'origen'=>'admin',
            'revisado_at'=>now(),
            'revisado_por'=>$request->user()?->id,
        ]);
        $empresa->update(['metodo_pago_preferido'=>$data['metodo']]);

        $this->refreshProjectPaymentStatus($proyecto);
        return response()->json(['data'=>$this->projectRow($this->reloadProject($proyecto))],201);
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
            'dias_prueba'=>['nullable','integer','min:0','max:60'],
            'dias_gracia'=>['required','integer','min:0','max:60'],
            'estado'=>['nullable',Rule::in(['activa','gracia','suspendida','cancelada'])],
        ]);

        $empresa = Empresa::with('planViti')->findOrFail($aplicacion->empresa_id);
        $start = Carbon::parse($data['fecha_inicio'])->startOfDay();
        $trialDays = (int)($data['dias_prueba'] ?? $empresa->planViti?->dias_prueba ?? 14);
        $trialEnd = $trialDays > 0 ? $start->copy()->addDays($trialDays - 1) : null;
        $firstBillStart = $trialEnd ? $trialEnd->copy()->addDay() : $start->copy();
        $firstBillEnd = null;
        $firstAmount = null;

        if ($data['frecuencia'] === 'mensual') {
            $firstBillEnd = $firstBillStart->copy()->endOfMonth()->startOfDay();
            $billableDays = $firstBillStart->diffInDays($firstBillEnd) + 1;
            $daysInMonth = $firstBillStart->daysInMonth;
            $firstAmount = round(((float)$data['monto'] * $billableDays) / $daysInMonth, 2);
        }

        $due = !empty($data['fecha_vencimiento'])
            ? Carbon::parse($data['fecha_vencimiento'])->startOfDay()
            : ($data['frecuencia'] === 'anual'
                ? $firstBillStart->copy()->addYear()
                : $firstBillEnd);

        $subscription = Suscripcion::updateOrCreate(
            ['aplicacion_id'=>$aplicacion->id],
            [
                'empresa_id'=>$aplicacion->empresa_id,
                'plan'=>$data['plan'],
                'monto'=>$data['monto'],
                'frecuencia'=>$data['frecuencia'],
                'moneda'=>'BOB',
                'fecha_inicio'=>$start->toDateString(),
                'prueba_hasta'=>$trialEnd?->toDateString(),
                'primer_cobro_monto'=>$firstAmount,
                'primer_cobro_desde'=>$data['frecuencia'] === 'mensual' ? $firstBillStart->toDateString() : null,
                'primer_cobro_hasta'=>$firstBillEnd?->toDateString(),
                'primer_cobro_pagado'=>false,
                'fecha_vencimiento'=>$due?->toDateString(),
                'dias_gracia'=>$data['dias_gracia'],
                'estado'=>$data['estado'] ?? 'activa',
            ]
        );

        $subscription = $access->refresh($subscription->fresh());
        return response()->json(['data'=>$this->subscriptionRow($this->reloadSubscription($subscription))]);
    }

    public function registrarPagoSuscripcion(Request $request, Suscripcion $suscripcion, SubscriptionAccessService $access): JsonResponse
    {
        abort_if($suscripcion->pagos()->where('estado_revision','pendiente_revision')->exists(),422,'Hay un comprobante del cliente pendiente de revisión. Confírmalo o recházalo antes de registrar otro pago.');
        $data = $request->validate([
            'monto'=>['required','numeric','min:0.01'],
            'metodo'=>['required',Rule::in(self::PAYMENT_METHODS)],
            'fecha_pago'=>['required','date'],
            'referencia'=>['nullable','string','max:180'],
            'observaciones'=>['nullable','string','max:3000'],
            'pagador_usuario_id'=>['nullable','integer','exists:usuarios,id'],
        ]);

        $empresa = Empresa::findOrFail($suscripcion->empresa_id);
        $this->assertPayerBelongsToCompany($empresa,$data['pagador_usuario_id'] ?? null);

        DB::transaction(function () use ($request,$suscripcion,$empresa,$data): void {
            SuscripcionPago::create([
                ...$data,
                'suscripcion_id'=>$suscripcion->id,
                'empresa_id'=>$empresa->id,
                'registrado_por'=>$request->user()?->id,
                'estado_revision'=>'confirmado',
                'origen'=>'admin',
                'revisado_at'=>now(),
                'revisado_por'=>$request->user()?->id,
            ]);

            $firstPaymentDone = (bool)$suscripcion->primer_cobro_pagado;
            if (!$firstPaymentDone && $suscripcion->primer_cobro_monto !== null) {
                $confirmed = (float)$suscripcion->pagos()->where('estado_revision','confirmado')->sum('monto');
                $firstPaymentDone = $confirmed + 0.001 >= (float)$suscripcion->primer_cobro_monto;
            }

            $base = $suscripcion->fecha_vencimiento && $suscripcion->fecha_vencimiento->isFuture()
                ? $suscripcion->fecha_vencimiento->copy()
                : Carbon::parse($data['fecha_pago']);
            $next = $suscripcion->frecuencia === 'anual' ? $base->addYear() : $base->addMonthNoOverflow();
            $suscripcion->update([
                'fecha_vencimiento'=>$next->toDateString(),
                'primer_cobro_pagado'=>$firstPaymentDone,
                'estado'=>'activa',
            ]);
            $empresa->update(['metodo_pago_preferido'=>$data['metodo']]);
        });

        $suscripcion = $access->refresh($suscripcion->fresh());
        return response()->json(['data'=>$this->subscriptionRow($this->reloadSubscription($suscripcion))],201);
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

        $confirmed = $project->pagos->where('estado_revision','confirmado');
        $initialTarget = (float)($project->anticipo_monto ?? 0);
        $balanceTarget = (float)($project->saldo_monto ?? 0);
        $initialPaid = (float)$confirmed->where('tipo','anticipo')->sum('monto');
        $balancePaid = (float)$confirmed->whereIn('tipo',['saldo_final','otro'])->sum('monto');

        $status = 'pendiente_anticipo';
        if ($initialPaid + 0.001 >= $initialTarget) $status = 'pendiente_saldo';
        if (($initialPaid + $balancePaid) + 0.001 >= (float)$project->precio_acordado || ($initialPaid + 0.001 >= $initialTarget && $balancePaid + 0.001 >= $balanceTarget)) $status = 'pagado';
        $project->update(['estado_pago'=>$status]);
    }

    private function projectRow(Proyecto $p): array
    {
        $confirmed = $p->pagos->where('estado_revision','confirmado');
        $initialPaid = (float)$confirmed->where('tipo','anticipo')->sum('monto');
        $balancePaid = (float)$confirmed->whereIn('tipo',['saldo_final','otro'])->sum('monto');
        $paid = round($initialPaid + $balancePaid,2);
        $total = (float)($p->precio_acordado ?? 0);
        return [
            'id'=>$p->id,'codigo'=>$p->codigo,'nombre'=>$p->nombre,
            'empresa'=>$this->companyRow($p->empresa),'cuentas_empresa'=>$this->companyAccounts($p->empresa),
            'cliente'=>$p->cliente,'aplicacion'=>$p->aplicacion,
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
            'id'=>$s->id,'aplicacion'=>$s->aplicacion,
            'empresa'=>$this->companyRow($s->empresa),'cuentas_empresa'=>$this->companyAccounts($s->empresa),
            'plan'=>$s->plan,'monto'=>(float)$s->monto,
            'frecuencia'=>$s->frecuencia,'moneda'=>$s->moneda,'fecha_inicio'=>$s->fecha_inicio?->format('Y-m-d'),
            'prueba_hasta'=>$s->prueba_hasta?->format('Y-m-d'),
            'primer_cobro_monto'=>$s->primer_cobro_monto !== null ? (float)$s->primer_cobro_monto : null,
            'primer_cobro_desde'=>$s->primer_cobro_desde?->format('Y-m-d'),
            'primer_cobro_hasta'=>$s->primer_cobro_hasta?->format('Y-m-d'),
            'primer_cobro_pagado'=>(bool)$s->primer_cobro_pagado,
            'fecha_vencimiento'=>$s->fecha_vencimiento?->format('Y-m-d'),'dias_gracia'=>(int)$s->dias_gracia,'estado'=>$s->estado,
            'pagos'=>$s->pagos->values(),
        ];
    }

    private function reloadProject(Proyecto $project): Proyecto
    {
        return $project->fresh()->load([
            'empresa'=>fn($q)=>$q->select('id','nombre_comercial','metodo_pago_preferido')->with(['usuarios'=>fn($users)=>$users->wherePivot('activo',true)->select('usuarios.id','usuarios.nombre','usuarios.apellido','usuarios.usuario','usuarios.telefono')->orderBy('usuarios.nombre')]),
            'cliente:id,nombre,telefono','aplicacion:id,proyecto_id,nombre,estado,acceso_cliente','pagos.pagador:id,nombre,apellido,usuario,telefono','pagos.revisor:id,nombre,apellido,usuario',
        ]);
    }

    private function reloadSubscription(Suscripcion $subscription): Suscripcion
    {
        return $subscription->fresh()->load([
            'aplicacion:id,empresa_id,proyecto_id,nombre,estado,acceso_cliente',
            'empresa'=>fn($q)=>$q->select('id','nombre_comercial','metodo_pago_preferido')->with(['usuarios'=>fn($users)=>$users->wherePivot('activo',true)->select('usuarios.id','usuarios.nombre','usuarios.apellido','usuarios.usuario','usuarios.telefono')->orderBy('usuarios.nombre')]),
            'pagos.pagador:id,nombre,apellido,usuario,telefono','pagos.revisor:id,nombre,apellido,usuario',
        ]);
    }

    private function companyRow(?Empresa $empresa): ?array
    {
        if (!$empresa) return null;
        return ['id'=>$empresa->id,'nombre_comercial'=>$empresa->nombre_comercial,'metodo_pago_preferido'=>$empresa->metodo_pago_preferido ?: 'qr'];
    }

    private function companyAccounts(?Empresa $empresa): array
    {
        if (!$empresa) return [];
        return $empresa->usuarios->map(fn($user)=>[
            'id'=>$user->id,
            'nombre'=>trim($user->nombre.' '.($user->apellido ?? '')),
            'usuario'=>$user->usuario,
            'telefono'=>$user->telefono,
            'rol_negocio'=>$user->pivot?->rol_negocio,
        ])->values()->all();
    }

    private function assertPayerBelongsToCompany(Empresa $empresa, ?int $userId): void
    {
        if (!$userId) return;
        abort_unless(
            $empresa->usuarios()->where('usuarios.id',$userId)->wherePivot('activo',true)->exists(),
            422,
            'La cuenta seleccionada no pertenece a esta empresa o está inactiva.'
        );
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
