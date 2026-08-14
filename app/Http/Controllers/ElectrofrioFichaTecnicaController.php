<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,ElectrofrioEquipo,ElectrofrioFichaTecnica,Empresa};
use App\Services\{SubscriptionAccessService,TenantContext};
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ElectrofrioFichaTecnicaController extends Controller
{
    public function index(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);

        $items = ElectrofrioFichaTecnica::query()
            ->where('empresa_id', $empresa->id)
            ->with(['equipo.cliente:id,nombre'])
            ->latest('updated_at')
            ->limit(300)
            ->get();

        return response()->json(['data' => $items]);
    }

    public function show(Request $request, TenantContext $tenants, int $equipoId): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        $equipo = $this->equipo($empresa, $equipoId);

        $ficha = ElectrofrioFichaTecnica::query()
            ->where('empresa_id', $empresa->id)
            ->where('equipo_id', $equipo->id)
            ->with(['equipo.cliente:id,nombre'])
            ->first();

        return response()->json(['data' => $ficha]);
    }

    public function guardar(Request $request, TenantContext $tenants, int $equipoId): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        $tenants->assertCanManage($request->user(), $empresa);
        $equipo = $this->equipo($empresa, $equipoId);

        $data = $request->validate([
            'gas_refrigerante' => ['nullable', 'string', 'max:60'],
            'voltaje' => ['nullable', 'string', 'max:30'],
            'amperaje_nominal' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'presion_succion_psi' => ['nullable', 'numeric', 'min:-1000', 'max:99999.99'],
            'presion_descarga_psi' => ['nullable', 'numeric', 'min:-1000', 'max:99999.99'],
            'observaciones_tecnicas' => ['nullable', 'string', 'max:8000'],
        ]);

        foreach (['gas_refrigerante', 'voltaje'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) ($data[$field] ?? ''));
                $data[$field] = $value === '' ? null : strtoupper($value);
            }
        }

        if (array_key_exists('observaciones_tecnicas', $data)) {
            $value = trim((string) ($data['observaciones_tecnicas'] ?? ''));
            $data['observaciones_tecnicas'] = $value === '' ? null : $value;
        }

        $ficha = ElectrofrioFichaTecnica::query()->firstOrNew([
            'empresa_id' => $empresa->id,
            'equipo_id' => $equipo->id,
        ]);
        $creada = !$ficha->exists;
        $ficha->fill($data);
        $ficha->actualizado_por = $request->user()->id;
        $ficha->save();

        Audit::log(
            $request,
            $creada ? 'electrofrio_ficha_tecnica_creada' : 'electrofrio_ficha_tecnica_actualizada',
            $ficha,
            $creada ? 'Se creó la ficha técnica de un equipo de Electrofrío.' : 'Se actualizó la ficha técnica de un equipo de Electrofrío.',
            ['equipo_id' => $equipo->id]
        );

        return response()->json([
            'message' => $creada ? 'Ficha técnica creada.' : 'Ficha técnica actualizada.',
            'data' => $ficha->fresh()->load(['equipo.cliente:id,nombre']),
        ], $creada ? 201 : 200);
    }

    private function empresa(Request $request, TenantContext $tenants): Empresa
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanUse($request->user(), $empresa, 'equipos');

        if (!$request->user()->isPlatformAdmin()) {
            $app = Aplicacion::query()
                ->where('empresa_id', $empresa->id)
                ->whereHas('catalogo', fn ($query) => $query->where('clave', 'electrofrio'))
                ->with('suscripcion')
                ->latest('id')
                ->first();

            abort_unless($app, 404, 'Este negocio no tiene Electrofrío asignado.');
            abort_unless((bool) $app->acceso_cliente, 403, 'Electrofrío todavía no fue entregado a este negocio.');
            abort_unless($app->estado === 'activo', 403, 'El acceso a Electrofrío está suspendido.');
            app(SubscriptionAccessService::class)->assertCanUse($app);
        }

        return $empresa;
    }

    private function equipo(Empresa $empresa, int $equipoId): ElectrofrioEquipo
    {
        $equipo = ElectrofrioEquipo::query()
            ->where('empresa_id', $empresa->id)
            ->find($equipoId);

        abort_unless($equipo, 404, 'El equipo solicitado no existe en este negocio.');
        return $equipo;
    }
}
