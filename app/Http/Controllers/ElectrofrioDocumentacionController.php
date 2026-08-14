<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Empresa};
use App\Services\{SubscriptionAccessService,TenantContext};
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\{JsonResponse,Request,Response};
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ElectrofrioDocumentacionController extends Controller
{
    private const CATEGORIES = ['antes', 'durante', 'despues', 'documento', 'comprobante'];

    public function subir(Request $request, TenantContext $tenants, int $id): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants, 'ordenes');
        $orden = $this->orden($empresa, $id);
        abort_if($orden->etapa === 'cerrada', 422, 'La orden está cerrada y sus evidencias forman parte del historial.');

        $data = $request->validate([
            'archivo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:12288'],
            'categoria' => ['required', Rule::in(self::CATEGORIES)],
            'descripcion' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $request->file('archivo');
        $path = $file->store('electrofrio/'.now()->format('Y/m'), 'private_uploads');

        try {
            $evidenceId = DB::table('electrofrio_evidencias')->insertGetId([
                'empresa_id' => $empresa->id,
                'orden_id' => $orden->id,
                'subido_por' => $request->user()->id,
                'categoria' => $data['categoria'],
                'nombre_original' => $file->getClientOriginalName(),
                'ruta' => $path,
                'mime' => $file->getMimeType(),
                'tamano' => $file->getSize(),
                'descripcion' => isset($data['descripcion']) ? trim((string) $data['descripcion']) ?: null : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('private_uploads')->delete($path);
            throw $e;
        }

        Audit::log($request, 'electrofrio_evidencia_subida', null, 'Se agregó evidencia privada a una orden de Electrofrío.', [
            'orden_id' => $orden->id,
            'evidencia_id' => $evidenceId,
            'categoria' => $data['categoria'],
        ]);

        return response()->json(['data' => $this->evidencia($empresa, $evidenceId)], 201);
    }

    public function descargar(Request $request, TenantContext $tenants, int $evidencia): StreamedResponse
    {
        $empresa = $this->empresa($request, $tenants, 'ordenes');
        $item = $this->evidencia($empresa, $evidencia);
        $this->orden($empresa, (int) $item->orden_id);

        $disk = Storage::disk('private_uploads');
        abort_unless($disk->exists($item->ruta), 404, 'El archivo ya no está disponible.');
        $stream = $disk->readStream($item->ruta);
        abort_unless(is_resource($stream), 404, 'No pudimos abrir la evidencia.');

        return response()->streamDownload(
            function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            $item->nombre_original,
            [
                'Content-Type' => $item->mime ?: 'application/octet-stream',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function eliminar(Request $request, TenantContext $tenants, int $evidencia): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants, 'ordenes');
        $item = $this->evidencia($empresa, $evidencia);
        $orden = $this->orden($empresa, (int) $item->orden_id);
        abort_if($orden->etapa === 'cerrada', 422, 'Las evidencias de una orden cerrada forman parte del historial y ya no pueden eliminarse.');

        Storage::disk('private_uploads')->delete($item->ruta);
        DB::table('electrofrio_evidencias')->where('id', $item->id)->delete();
        Audit::log($request, 'electrofrio_evidencia_eliminada', null, 'Se eliminó evidencia de una orden abierta de Electrofrío.', [
            'orden_id' => $orden->id,
            'evidencia_id' => $item->id,
        ]);

        return response()->json(status: 204);
    }

    public function pdf(Request $request, TenantContext $tenants, int $id): Response
    {
        $empresa = $this->empresa($request, $tenants, 'ordenes');
        $orden = $this->ordenCompleta($empresa, $id);
        $ficha = null;
        if ($orden->equipo_id) {
            $ficha = DB::table('electrofrio_fichas_tecnicas')
                ->where('empresa_id', $empresa->id)
                ->where('equipo_id', $orden->equipo_id)
                ->first();
        }

        Audit::log($request, 'electrofrio_orden_pdf_generado', null, 'Se generó el PDF de una orden de Electrofrío.', [
            'orden_id' => $orden->id,
        ]);

        return Pdf::loadView('reports.electrofrio-orden', compact('empresa', 'orden', 'ficha'))
            ->setPaper('a4')
            ->download($orden->codigo.'-electrofrio.pdf');
    }

    private function empresa(Request $request, TenantContext $tenants, string $module): Empresa
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanUse($request->user(), $empresa, $module);

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

    private function orden(Empresa $empresa, int $id): object
    {
        $item = DB::table('electrofrio_ordenes')
            ->where('empresa_id', $empresa->id)
            ->where('id', $id)
            ->first();
        abort_unless($item, 404, 'La orden solicitada no existe en este negocio.');
        return $item;
    }

    private function evidencia(Empresa $empresa, int $id): object
    {
        $item = DB::table('electrofrio_evidencias')
            ->where('empresa_id', $empresa->id)
            ->where('id', $id)
            ->first();
        abort_unless($item, 404, 'La evidencia no existe.');
        return $item;
    }

    private function ordenCompleta(Empresa $empresa, int $id): object
    {
        $order = DB::table('electrofrio_ordenes as o')
            ->join('electrofrio_clientes as c', 'c.id', '=', 'o.cliente_id')
            ->leftJoin('electrofrio_equipos as e', 'e.id', '=', 'o.equipo_id')
            ->leftJoin('electrofrio_tecnicos as t', 't.id', '=', 'o.tecnico_id')
            ->where('o.empresa_id', $empresa->id)
            ->where('o.id', $id)
            ->select(
                'o.*',
                'c.nombre as cliente_nombre',
                'c.telefono as cliente_telefono',
                'c.direccion as cliente_direccion',
                'e.tipo as equipo_tipo',
                'e.marca as equipo_marca',
                'e.modelo as equipo_modelo',
                'e.serie as equipo_serie',
                'e.capacidad as equipo_capacidad',
                'e.ubicacion as equipo_ubicacion',
                't.nombre as tecnico_nombre'
            )
            ->first();
        abort_unless($order, 404, 'La orden solicitada no existe en este negocio.');

        $order->materiales = DB::table('electrofrio_orden_material as om')
            ->join('electrofrio_materiales as m', 'm.id', '=', 'om.material_id')
            ->where('om.empresa_id', $empresa->id)
            ->where('om.orden_id', $order->id)
            ->select('om.*', 'm.nombre as material_nombre', 'm.unidad as material_unidad')
            ->orderBy('m.nombre')
            ->get();
        $order->pagos = DB::table('electrofrio_pagos')
            ->where('empresa_id', $empresa->id)
            ->where('orden_id', $order->id)
            ->where('estado', 'pagado')
            ->orderBy('pagado_at')
            ->get();
        $order->evidencias = DB::table('electrofrio_evidencias')
            ->where('empresa_id', $empresa->id)
            ->where('orden_id', $order->id)
            ->orderBy('categoria')
            ->orderBy('id')
            ->get();
        $order->pagado = (float) $order->pagos->sum('monto');
        $order->saldo = max(0, (float) $order->total - $order->pagado);

        return $order;
    }
}
