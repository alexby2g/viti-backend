<?php

namespace App\Observers;

use App\Models\Proyecto;
use App\Services\WorkflowStateService;

class ProyectoWorkflowObserver
{
    public function updating(Proyecto $proyecto): void
    {
        $workflow = app(WorkflowStateService::class);

        if ($proyecto->isDirty('fase')) {
            $workflow->assertProyectoPhaseTransition(
                (string)$proyecto->getOriginal('fase'),
                (string)$proyecto->fase,
            );
        }

        if ($proyecto->isDirty('estado')) {
            $workflow->assertProyectoStateTransition(
                (string)$proyecto->getOriginal('estado'),
                (string)$proyecto->estado,
            );
        }
    }
}
