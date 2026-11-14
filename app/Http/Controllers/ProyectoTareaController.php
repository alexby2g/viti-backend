<?php

namespace App\Http\Controllers;

use App\Models\Proyecto;
use App\Models\ProyectoTarea;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProyectoTareaController extends Controller
{
    private const ESTADOS = ['pendiente', 'en_desarrollo', 'en_revision', 'bloqueada', 'completada'];
    private const PRIORIDADES = ['baja', 'media', 'alta', 'critica'];
    private const AREAS = ['general', 'analisis', 'diseno', 'frontend', 'backend', 'movil', 'infraestructura', 'qa'];

    public function index(Proyecto $proyecto): JsonResponse
    {
        $items = $proyecto->tareas()
            ->with('responsable:id,nombre,apellido')
            ->orderBy('id')
            ->limit(250)
            ->get();

        return response()->json([
            'data' => $items,
            'summary' => [
                'total' => $items->count(),
                'completadas' => $items->where('estado', 'completada')->count(),
                'bloqueadas' => $items->where('estado', 'bloqueada')->count(),
                'pendientes' => $items->where('estado', 'pendiente')->count(),
                'en_desarrollo' => $items->where('estado', 'en_desarrollo')->count(),
                'en_revision' => $items->where('estado', 'en_revision')->count(),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Proyecto $proyecto): JsonResponse
    {
        $this->assertEditable($proyecto);
        if ($proyecto->tareas()->count() >= 250) {
            throw ValidationException::withMessages(['tareas' => 'Este trabajo alcanzó el límite de 250 tareas.']);
        }

        $data = $this->validated($request);
        $this->assertProjectMember($proyecto, $data['responsable_id'] ?? null);
        $this->assertCompletionCriteria($data['estado'] ?? 'pendiente', $data['criterios_aceptacion'] ?? null);

        $tarea = $proyecto->tareas()->create($data + [
            'creado_por' => $request->user()->id,
            'estado' => 'pendiente',
            'prioridad' => 'media',
            'area' => 'general',
        ]);

        if ($tarea->estado === 'completada') {
            $tarea->forceFill(['completada_at' => now()])->save();
        }

        Audit::log($request, 'proyecto_tarea_creada', $proyecto, 'Tarea de desarrollo creada.', [
            'tarea_id' => $tarea->id,
        ]);

        return response()->json([
            'data' => $tarea->load('responsable:id,nombre,apellido'),
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Proyecto $proyecto, int $tarea): JsonResponse
    {
        $this->assertEditable($proyecto);
        // Restringir siempre la tarea al proyecto solicitado (sin referencias cruzadas).
        $record = $proyecto->tareas()->findOrFail($tarea);
        $data = $this->validated($request, true);
        if (array_key_exists('responsable_id', $data)) {
            $this->assertProjectMember($proyecto, $data['responsable_id']);
        }

        $targetStatus = $data['estado'] ?? $record->estado;
        $targetCriteria = array_key_exists('criterios_aceptacion', $data)
            ? $data['criterios_aceptacion'] : $record->criterios_aceptacion;
        $this->assertCompletionCriteria($targetStatus, $targetCriteria);

        if ($targetStatus === 'completada' && $record->estado !== 'completada') {
            $data['completada_at'] = now();
        } elseif ($targetStatus !== 'completada' && $record->estado === 'completada') {
            $data['completada_at'] = null;
        }

        $record->update($data);
        Audit::log($request, 'proyecto_tarea_actualizada', $proyecto, 'Tarea de desarrollo actualizada.', [
            'tarea_id' => $record->id,
            'estado' => $record->estado,
        ]);

        // No modificar nunca proyecto.progreso/fase/estado desde el tablero.
        return response()->json([
            'data' => $record->fresh()->load('responsable:id,nombre,apellido'),
        ])->header('Cache-Control', 'no-store');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'titulo' => [$required, 'string', 'min:3', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'criterios_aceptacion' => ['nullable', 'string', 'max:4000'],
            'area' => ['sometimes', 'string', Rule::in(self::AREAS)],
            'estado' => ['sometimes', 'string', Rule::in(self::ESTADOS)],
            'prioridad' => ['sometimes', 'string', Rule::in(self::PRIORIDADES)],
            'responsable_id' => ['nullable', 'integer', 'exists:usuarios,id'],
            'fecha_limite' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    private function assertCompletionCriteria(string $estado, ?string $criterios): void
    {
        if ($estado === 'completada' && trim((string) $criterios) === '') {
            throw ValidationException::withMessages([
                'criterios_aceptacion' => 'Define criterios de aceptación antes de completar la tarea.',
            ]);
        }
    }

    private function assertProjectMember(Proyecto $proyecto, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        if ($userId === (int) $proyecto->responsable_id) {
            return;
        }

        $isMember = $proyecto->miembros()
            ->where('usuario_id', $userId)
            ->where('activo', true)
            ->exists();

        if (!$isMember) {
            throw ValidationException::withMessages([
                'responsable_id' => 'Solo puedes asignar tareas a miembros activos de este trabajo.',
            ]);
        }
    }

    private function assertEditable(Proyecto $proyecto): void
    {
        if (in_array($proyecto->estado, ['cancelado', 'finalizado'], true)) {
            throw ValidationException::withMessages([
                'estado' => 'Este trabajo está cerrado. No se pueden modificar sus tareas.',
            ]);
        }
    }
}
