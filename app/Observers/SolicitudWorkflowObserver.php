<?php

namespace App\Observers;

use App\Models\SolicitudSistema;
use App\Services\WorkflowStateService;
use Illuminate\Validation\ValidationException;

class SolicitudWorkflowObserver
{
    public function updating(SolicitudSistema $solicitud): void
    {
        if (!$solicitud->isDirty('estado')) return;

        app(WorkflowStateService::class)->assertSolicitudTransition(
            (string)$solicitud->getOriginal('estado'),
            (string)$solicitud->estado,
        );

        if ($solicitud->estado === 'convertida' && !$solicitud->proyecto()->exists()) {
            throw ValidationException::withMessages([
                'estado' => 'Una solicitud solo puede convertirse cuando el proyecto asociado ya existe.',
            ]);
        }
    }
}
