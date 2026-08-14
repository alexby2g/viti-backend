<?php

namespace App\Observers;

use App\Models\Proyecto;
use App\Services\WorkflowStateService;

class ProyectoWorkflowObserver
{
    public function updating(Proyecto $proyecto): void
    {
        $workflow = app(WorkflowStateService::class);
        $originalPhase = (string)$proyecto->getOriginal('fase');
        $originalState = (string)$proyecto->getOriginal('estado');

        if ($proyecto->isDirty('fase')) {
            $workflow->assertProyectoPhaseTransition(
                $originalPhase,
                (string)$proyecto->fase,
            );
        }

        if ($proyecto->isDirty('estado')) {
            $workflow->assertProyectoStateTransition(
                $originalState,
                (string)$proyecto->estado,
            );
        }

        $workflow->assertProyectoIntegrity(
            $originalPhase,
            $originalState,
            (string)$proyecto->fase,
            (string)$proyecto->estado,
            (int)$proyecto->progreso,
        );
    }
}
