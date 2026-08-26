<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\PeluqueriaAtencion;
use App\Models\PeluqueriaCita;
use App\Models\PeluqueriaCliente;
use App\Models\PeluqueriaPago;
use App\Models\PeluqueriaPersonal;
use App\Models\PeluqueriaServicio;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PeluqueriaController extends Controller
{
    private function empresaId(Request $request, string $module): int
    {
        $tenants = app(TenantContext::class);
        $empresa = $tenants->resolve($request);

        // Peluquería reutiliza las claves canónicas existentes de VITI.
        // No se crean aquí claves nuevas: el plan permite, la empresa habilita
        // y finalmente el usuario debe tener permiso para la capacidad.
        $tenants->assertModule($empresa, $module);
        $tenants->assertCanUse($request->user(), $empresa, $module);
        $request->attributes->set('viti_peluqueria_modules', $tenants->effectiveModules($request->user(), $empresa));

        return (int) $empresa->id;
    }

    private function hasModule(Request $request, string $module): bool
    {
        $modules = $request->attributes->get('viti_peluqueria_modules');
        return $modules === null || in_array($module, $modules, true);
    }

    private function scoped(string $model, int $empresaId, int $id)
    {
        return $model::where('empresa_id', $empresaId)->findOrFail($id);
    }

    public function resumen(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'inicio');
        $hoy = now()->toDateString();
        $canAgenda = $this->hasModule($request, 'agenda');
        $canClientes = $this->hasModule($request, 'clientes');
        $canOrdenes = $this->hasModule($request, 'ordenes');
        $canPagos = $this->hasModule($request, 'pagos');

        $citasHoy = $canAgenda
            ? PeluqueriaCita::with(['cliente:id,nombre','servicio:id,nombre','personal:id,nombre'])
                ->where('empresa_id', $empresaId)
                ->whereDate('fecha', $hoy)
                ->orderBy('hora_inicio')
                ->get()
            : collect();

        $ingresosHoy = $canPagos
            ? PeluqueriaPago::where('empresa_id', $empresaId)->whereDate('pagado_at', $hoy)->sum('monto')
            : null;

        return response()->json(['data' => [
            'clientes' => $canClientes ? PeluqueriaCliente::where('empresa_id', $empresaId)->where('activo', true)->count() : null,
            'citas_hoy' => $canAgenda ? $citasHoy->count() : null,
            'en_atencion' => $canOrdenes ? PeluqueriaAtencion::where('empresa_id', $empresaId)->where('estado', 'en_atencion')->count() : null,
            'atenciones_hoy' => $canOrdenes ? PeluqueriaAtencion::where('empresa_id', $empresaId)->whereDate('finalizada_at', $hoy)->count() : null,
            'ingresos_hoy' => $canPagos ? (float) $ingresosHoy : null,
            'agenda_hoy' => $canAgenda ? $citasHoy : [],
        ]]);
    }

    public function clientes(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'clientes');
        $q = PeluqueriaCliente::where('empresa_id', $empresaId)->latest();
        if ($request->filled('buscar')) {
            $term = '%'.$request->string('buscar').'%';
            $q->where(fn ($x) => $x->where('nombre','like',$term)->orWhere('telefono','like',$term)->orWhere('whatsapp','like',$term));
        }
        return response()->json(['data' => $q->get()]);
    }

    public function guardarCliente(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'clientes');
        $data = $request->validate([
            'nombre' => ['required','string','max:180'],
            'telefono' => ['nullable','string','max:30'],
            'whatsapp' => ['nullable','string','max:30'],
            'fecha_nacimiento' => ['nullable','date'],
            'sexo' => ['nullable','string','max:30'],
            'direccion' => ['nullable','string','max:255'],
            'observaciones' => ['nullable','string','max:3000'],
            'activo' => ['sometimes','boolean'],
        ]);
        $item = PeluqueriaCliente::create($data + ['empresa_id' => $empresaId]);
        return response()->json(['data' => $item], 201);
    }

    public function actualizarCliente(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'clientes');
        $item = $this->scoped(PeluqueriaCliente::class, $empresaId, $id);
        $data = $request->validate([
            'nombre' => ['required','string','max:180'],
            'telefono' => ['nullable','string','max:30'],
            'whatsapp' => ['nullable','string','max:30'],
            'fecha_nacimiento' => ['nullable','date'],
            'sexo' => ['nullable','string','max:30'],
            'direccion' => ['nullable','string','max:255'],
            'observaciones' => ['nullable','string','max:3000'],
            'activo' => ['sometimes','boolean'],
        ]);
        $item->update($data);
        return response()->json(['data' => $item->fresh()]);
    }

    public function eliminarCliente(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'clientes');
        $item = $this->scoped(PeluqueriaCliente::class, $empresaId, $id);
        abort_if($item->citas()->exists() || $item->atenciones()->exists(), 422, 'El cliente tiene historial y no puede eliminarse. Puedes desactivarlo.');
        $item->delete();
        return response()->json(status: 204);
    }

    public function servicios(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'ordenes');
        return response()->json(['data' => PeluqueriaServicio::where('empresa_id',$empresaId)->orderBy('categoria')->orderBy('nombre')->get()]);
    }

    public function guardarServicio(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'ordenes');
        $data = $request->validate([
            'nombre' => ['required','string','max:160'],
            'categoria' => ['nullable','string','max:100'],
            'duracion_minutos' => ['required','integer','min:5','max:720'],
            'precio' => ['required','numeric','min:0'],
            'descripcion' => ['nullable','string','max:3000'],
            'activo' => ['sometimes','boolean'],
        ]);
        return response()->json(['data' => PeluqueriaServicio::create($data + ['empresa_id'=>$empresaId])], 201);
    }

    public function actualizarServicio(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'ordenes');
        $item = $this->scoped(PeluqueriaServicio::class, $empresaId, $id);
        $data = $request->validate([
            'nombre' => ['required','string','max:160'],
            'categoria' => ['nullable','string','max:100'],
            'duracion_minutos' => ['required','integer','min:5','max:720'],
            'precio' => ['required','numeric','min:0'],
            'descripcion' => ['nullable','string','max:3000'],
            'activo' => ['sometimes','boolean'],
        ]);
        $item->update($data);
        return response()->json(['data'=>$item->fresh()]);
    }

    public function eliminarServicio(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'ordenes');
        $item = $this->scoped(PeluqueriaServicio::class, $empresaId, $id);
        abort_if($item->citas()->exists() || $item->atenciones()->exists(), 422, 'El servicio ya tiene historial y no puede eliminarse. Puedes desactivarlo.');
        $item->delete();
        return response()->json(status:204);
    }

    public function personal(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'tecnicos');
        return response()->json(['data' => PeluqueriaPersonal::where('empresa_id',$empresaId)->orderBy('nombre')->get()]);
    }

    public function guardarPersonal(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'tecnicos');
        $data = $request->validate([
            'nombre' => ['required','string','max:180'],
            'telefono' => ['nullable','string','max:30'],
            'especialidad' => ['nullable','string','max:160'],
            'horario_inicio' => ['nullable','date_format:H:i'],
            'horario_fin' => ['nullable','date_format:H:i','after:horario_inicio'],
            'porcentaje_comision' => ['nullable','numeric','min:0','max:100'],
            'activo' => ['sometimes','boolean'],
        ]);
        return response()->json(['data'=>PeluqueriaPersonal::create($data + ['empresa_id'=>$empresaId])],201);
    }

    public function actualizarPersonal(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'tecnicos');
        $item = $this->scoped(PeluqueriaPersonal::class,$empresaId,$id);
        $data = $request->validate([
            'nombre' => ['required','string','max:180'],
            'telefono' => ['nullable','string','max:30'],
            'especialidad' => ['nullable','string','max:160'],
            'horario_inicio' => ['nullable','date_format:H:i'],
            'horario_fin' => ['nullable','date_format:H:i','after:horario_inicio'],
            'porcentaje_comision' => ['nullable','numeric','min:0','max:100'],
            'activo' => ['sometimes','boolean'],
        ]);
        $item->update($data);
        return response()->json(['data'=>$item->fresh()]);
    }

    public function eliminarPersonal(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'tecnicos');
        $item = $this->scoped(PeluqueriaPersonal::class,$empresaId,$id);
        abort_if($item->citas()->exists() || $item->atenciones()->exists(),422,'Este trabajador tiene historial. Puedes desactivarlo.');
        $item->delete();
        return response()->json(status:204);
    }

    public function citas(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'agenda');
        $q = PeluqueriaCita::with(['cliente:id,nombre,telefono','servicio:id,nombre,precio,duracion_minutos','personal:id,nombre'])
            ->where('empresa_id',$empresaId)
            ->orderBy('fecha')->orderBy('hora_inicio');
        if ($request->filled('fecha')) $q->whereDate('fecha',$request->date('fecha'));
        if ($request->filled('estado')) $q->where('estado',$request->string('estado'));
        return response()->json(['data'=>$q->get()]);
    }

    public function guardarCita(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'agenda');
        $data = $this->datosCita($request,$empresaId);
        $this->validarCruceCita($empresaId,$data);
        $item = PeluqueriaCita::create($data + ['empresa_id'=>$empresaId]);
        return response()->json(['data'=>$item->load(['cliente','servicio','personal'])],201);
    }

    public function actualizarCita(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'agenda');
        $item = $this->scoped(PeluqueriaCita::class,$empresaId,$id);
        $data = $this->datosCita($request,$empresaId);
        $this->validarCruceCita($empresaId,$data,$id);
        $item->update($data);
        return response()->json(['data'=>$item->fresh()->load(['cliente','servicio','personal'])]);
    }

    public function eliminarCita(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'agenda');
        $item = $this->scoped(PeluqueriaCita::class,$empresaId,$id);
        abort_if($item->atencion()->exists(),422,'La cita ya tiene una atención asociada.');
        $item->delete();
        return response()->json(status:204);
    }

    private function datosCita(Request $request, int $empresaId): array
    {
        $data = $request->validate([
            'cliente_id' => ['required','integer'],
            'servicio_id' => ['required','integer'],
            'personal_id' => ['nullable','integer'],
            'fecha' => ['required','date'],
            'hora_inicio' => ['required','date_format:H:i'],
            'hora_fin' => ['nullable','date_format:H:i'],
            'estado' => ['nullable',Rule::in(['pendiente','confirmada','en_espera','en_atencion','finalizada','cancelada','no_asistio'])],
            'notas' => ['nullable','string','max:3000'],
        ]);

        $cliente = $this->scoped(PeluqueriaCliente::class,$empresaId,(int)$data['cliente_id']);
        $servicio = $this->scoped(PeluqueriaServicio::class,$empresaId,(int)$data['servicio_id']);
        if (!empty($data['personal_id'])) $this->scoped(PeluqueriaPersonal::class,$empresaId,(int)$data['personal_id']);

        if (empty($data['hora_fin'])) {
            $data['hora_fin'] = Carbon::createFromFormat('H:i',$data['hora_inicio'])->addMinutes((int)$servicio->duracion_minutos)->format('H:i');
        }
        abort_if($data['hora_fin'] <= $data['hora_inicio'],422,'La hora de finalización debe ser posterior a la hora de inicio.');
        return $data;
    }

    private function validarCruceCita(int $empresaId, array $data, ?int $ignoreId = null): void
    {
        if (empty($data['personal_id']) || in_array($data['estado'] ?? 'pendiente',['cancelada','no_asistio'],true)) return;
        $q = PeluqueriaCita::where('empresa_id',$empresaId)
            ->where('personal_id',$data['personal_id'])
            ->whereDate('fecha',$data['fecha'])
            ->whereNotIn('estado',['cancelada','no_asistio'])
            ->where('hora_inicio','<',$data['hora_fin'])
            ->where('hora_fin','>',$data['hora_inicio']);
        if ($ignoreId) $q->whereKeyNot($ignoreId);
        abort_if($q->exists(),422,'Ese trabajador ya tiene una cita en ese horario.');
    }

    public function atenciones(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'ordenes');
        $relations = ['cliente:id,nombre,telefono','servicio:id,nombre','personal:id,nombre'];
        if ($this->hasModule($request, 'pagos')) $relations[] = 'pagos';
        $q = PeluqueriaAtencion::with($relations)->where('empresa_id',$empresaId)->latest('id');
        if ($request->filled('estado')) $q->where('estado',$request->string('estado'));
        return response()->json(['data'=>$q->limit(300)->get()]);
    }

    public function iniciarAtencion(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'ordenes');
        $data = $request->validate([
            'cita_id' => ['nullable','integer'],
            'cliente_id' => ['required','integer'],
            'servicio_id' => ['required','integer'],
            'personal_id' => ['nullable','integer'],
            'descuento' => ['nullable','numeric','min:0'],
            'observaciones' => ['nullable','string','max:3000'],
        ]);

        $cliente = $this->scoped(PeluqueriaCliente::class,$empresaId,(int)$data['cliente_id']);
        $servicio = $this->scoped(PeluqueriaServicio::class,$empresaId,(int)$data['servicio_id']);
        if (!empty($data['personal_id'])) $this->scoped(PeluqueriaPersonal::class,$empresaId,(int)$data['personal_id']);
        $cita = !empty($data['cita_id']) ? $this->scoped(PeluqueriaCita::class,$empresaId,(int)$data['cita_id']) : null;
        abort_if($cita && $cita->atencion()->exists(),422,'Esta cita ya fue convertida en atención.');

        $descuento = (float)($data['descuento'] ?? 0);
        $precio = (float)$servicio->precio;
        abort_if($descuento > $precio,422,'El descuento no puede ser mayor al precio del servicio.');

        $item = DB::transaction(function () use ($empresaId,$data,$precio,$descuento,$cita): PeluqueriaAtencion {
            if ($cita) $cita->update(['estado'=>'en_atencion']);
            return PeluqueriaAtencion::create([
                'empresa_id'=>$empresaId,
                'cita_id'=>$cita?->id,
                'cliente_id'=>$data['cliente_id'],
                'servicio_id'=>$data['servicio_id'],
                'personal_id'=>$data['personal_id'] ?? null,
                'estado'=>'en_atencion',
                'iniciada_at'=>now(),
                'precio_servicio'=>$precio,
                'descuento'=>$descuento,
                'total'=>$precio-$descuento,
                'observaciones'=>$data['observaciones'] ?? null,
            ]);
        });

        $relations = ['cliente','servicio','personal'];
        if ($this->hasModule($request, 'pagos')) $relations[] = 'pagos';
        return response()->json(['data'=>$item->load($relations)],201);
    }

    public function finalizarAtencion(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'ordenes');
        $item = $this->scoped(PeluqueriaAtencion::class,$empresaId,$id);
        $data = $request->validate([
            'observaciones' => ['nullable','string','max:3000'],
            'descuento' => ['nullable','numeric','min:0'],
        ]);
        $descuento = array_key_exists('descuento',$data) ? (float)$data['descuento'] : (float)$item->descuento;
        abort_if($descuento > (float)$item->precio_servicio,422,'El descuento no puede ser mayor al precio del servicio.');
        DB::transaction(function () use ($item,$data,$descuento): void {
            $item->update([
                'estado'=>'finalizada',
                'finalizada_at'=>now(),
                'descuento'=>$descuento,
                'total'=>(float)$item->precio_servicio-$descuento,
                'observaciones'=>$data['observaciones'] ?? $item->observaciones,
            ]);
            if ($item->cita_id) PeluqueriaCita::whereKey($item->cita_id)->update(['estado'=>'finalizada']);
        });
        $relations = ['cliente','servicio','personal'];
        if ($this->hasModule($request, 'pagos')) $relations[] = 'pagos';
        return response()->json(['data'=>$item->fresh()->load($relations)]);
    }

    public function registrarPago(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'pagos');
        $atencion = $this->scoped(PeluqueriaAtencion::class,$empresaId,$id);
        $data = $request->validate([
            'metodo' => ['required',Rule::in(['efectivo','qr','transferencia','tarjeta','otro'])],
            'monto' => ['required','numeric','gt:0'],
            'referencia' => ['nullable','string','max:120'],
        ]);
        $pagado = (float)$atencion->pagos()->sum('monto');
        abort_if($pagado + (float)$data['monto'] > (float)$atencion->total + 0.001,422,'El pago supera el total pendiente de la atención.');
        $pago = PeluqueriaPago::create($data + ['empresa_id'=>$empresaId,'atencion_id'=>$atencion->id,'pagado_at'=>now()]);
        return response()->json(['data'=>$pago],201);
    }

    public function historial(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request, 'historial');
        $relations = ['cliente:id,nombre,telefono','servicio:id,nombre','personal:id,nombre'];
        if ($this->hasModule($request, 'pagos')) $relations[] = 'pagos';
        $q = PeluqueriaAtencion::with($relations)->where('empresa_id',$empresaId)->where('estado','finalizada')->latest('finalizada_at');
        if ($request->filled('cliente_id')) $q->where('cliente_id',$request->integer('cliente_id'));
        return response()->json(['data'=>$q->limit(500)->get()]);
    }
}
