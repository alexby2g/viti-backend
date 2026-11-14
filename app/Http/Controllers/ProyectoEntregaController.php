<?php

namespace App\Http\Controllers;

use App\Models\{Archivo,Proyecto,ProyectoEntrega};
use App\Services\TenantContext;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProyectoEntregaController extends Controller
{
    // El manifiesto no mueve el proyecto de fase ni habilita una licencia.
    // Los accesos a aplicaciones permanecen sujetos a AplicacionController::entregar.
    public function show(Proyecto $proyecto): JsonResponse
    {
        $entrega = $proyecto->entrega;
        $files = $this->projectFiles($proyecto);
        return response()->json([
            'data' => $this->serialize($entrega, $files),
            'disponibles' => $files->values(),
        ])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, Proyecto $proyecto): JsonResponse
    {
        abort_if($proyecto->estado === 'cancelado', 422, 'El proyecto está cancelado.');

        $data = $request->validate([
            'titulo' => ['required', 'string', 'min:3', 'max:180'],
            'instrucciones' => ['nullable', 'string', 'max:5000'],
            'archivo_ids' => ['required', 'array', 'max:20'],
            'archivo_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $fileIds = array_values(array_map('intval', $data['archivo_ids']));
        $this->assertProjectFiles($proyecto, $fileIds);

        $entrega = DB::transaction(function () use ($proyecto, $data, $fileIds) {
            $record = ProyectoEntrega::query()->where('proyecto_id', $proyecto->id)
                ->lockForUpdate()->first();
            if ($record && $record->estado === 'publicada') {
                throw ValidationException::withMessages([
                    'estado' => 'Retira la entrega publicada antes de modificarla.',
                ]);
            }
            if (!$record) {
                $record = new ProyectoEntrega(['proyecto_id' => $proyecto->id]);
            }
            $record->fill([
                'titulo' => trim($data['titulo']),
                'instrucciones' => $data['instrucciones'] ?? null,
                'archivo_ids' => $fileIds,
                'estado' => 'borrador',
                'publicado_por' => null,
                'publicado_at' => null,
            ])->save();
            return $record;
        });

        Audit::log($request, 'proyecto_entrega_guardada', $proyecto, 'Se guardó el borrador de entrega.', [
            'entrega_id' => $entrega->id,
            'cantidad_archivos' => count($fileIds),
        ]);

        return response()->json(['data' => $this->serialize($entrega, $this->projectFiles($proyecto))])
            ->header('Cache-Control', 'no-store');
    }

    public function publish(Request $request, Proyecto $proyecto): JsonResponse
    {
        $entrega = DB::transaction(function () use ($proyecto, $request) {
            $lockedProject = Proyecto::query()->lockForUpdate()->findOrFail($proyecto->id);
            abort_if($lockedProject->estado === 'cancelado', 422, 'No se puede entregar un proyecto cancelado.');

            // Conservamos la regla existente del acto de entrega de aplicaciones.
            if ($lockedProject->precio_acordado !== null) {
                abort_unless($lockedProject->estado_pago === 'pagado', 422,
                    'El proyecto tiene un saldo pendiente. Verifica el pago antes de publicar la entrega.');
            }

            $record = ProyectoEntrega::query()->where('proyecto_id', $proyecto->id)
                ->lockForUpdate()->firstOrFail();

            if ($record->estado === 'publicada') return $record;

            $ids = array_values(array_map('intval', $record->archivo_ids ?: []));
            $this->assertProjectFiles($lockedProject, $ids);

            // También es válida una entrega solo de sistema, pero únicamente
            // si AplicacionController ya autorizó el acceso del cliente.
            $hasApp = $lockedProject->aplicacion()
                ->where('acceso_cliente', true)->whereNotNull('entregado_at')->exists();
            abort_unless(count($ids) || $hasApp, 422,
                'Adjunta al menos un entregable o habilita primero una aplicación para el cliente.');

            $record->forceFill([
                'estado' => 'publicada',
                'publicado_at' => now(),
                'publicado_por' => $request->user()->id,
            ])->save();

            return $record;
        });

        Audit::log($request, 'proyecto_entrega_publicada', $proyecto, 'Se publicó la entrega para el cliente.', [
            'entrega_id' => $entrega->id,
        ]);

        return response()->json(['data' => $this->serialize($entrega, $this->projectFiles($proyecto))])
            ->header('Cache-Control', 'no-store');
    }

    public function withdraw(Request $request, Proyecto $proyecto): JsonResponse
    {
        $entrega = DB::transaction(function () use ($proyecto) {
            $record = ProyectoEntrega::query()->where('proyecto_id', $proyecto->id)
                ->lockForUpdate()->firstOrFail();
            $record->forceFill([
                'estado' => 'borrador',
                'publicado_at' => null,
                'publicado_por' => null,
            ])->save();
            return $record;
        });

        Audit::log($request, 'proyecto_entrega_retirada', $proyecto,
            'Se retiró el paquete del cliente. Los archivos y la aplicación permanecen registrados.', [
                'entrega_id' => $entrega->id,
            ]);

        return response()->json(['data' => $this->serialize($entrega, $this->projectFiles($proyecto))])
            ->header('Cache-Control', 'no-store');
    }

    public function clientShow(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanUse($request->user(), $empresa, 'inicio');

        // Misma selección del proyecto que ClientProjectController.
        $proyecto = Proyecto::query()->where('empresa_id', $empresa->id)->latest()->first();
        $entrega = $proyecto?->entrega;
        if (!$entrega || $entrega->estado !== 'publicada') {
            return response()->json(['data' => null])->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'data' => $this->serialize($entrega, $this->projectFiles($proyecto), true),
        ])->header('Cache-Control', 'no-store');
    }

    private function projectFiles(Proyecto $proyecto)
    {
        return $proyecto->archivos()->where('categoria', 'entrega')
            ->orderBy('id', 'desc')
            ->get(['id','nombre_original','descripcion','mime','tamano']);
    }

    private function assertProjectFiles(Proyecto $proyecto, array $ids): void
    {
        if (!$ids) return;
        $count = $proyecto->archivos()
            ->where('categoria', 'entrega')
            ->whereIn('id', $ids)
            ->count();
        if ($count !== count($ids)) {
            throw ValidationException::withMessages([
                'archivo_ids' => 'Solo puedes incluir archivos de entrega de este mismo proyecto.',
            ]);
        }
    }

    private function serialize(?ProyectoEntrega $entrega, $available, bool $forClient = false): array
    {
        $ids = $entrega?->archivo_ids ?: [];
        $files = $available->filter(fn ($f) => in_array((int)$f->id, $ids, true))->values();

        return [
            'titulo' => $entrega?->titulo ?? 'Entrega del proyecto',
            'instrucciones' => $entrega?->instrucciones,
            'estado' => $entrega?->estado ?? 'borrador',
            'publicado_at' => $entrega?->publicado_at,
            'archivos' => $files,
            ...($forClient ? [] : [
                'id' => $entrega?->id,
                'archivo_ids' => $ids,
            ]),
        ];
    }
}
