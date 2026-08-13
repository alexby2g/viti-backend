<?php

namespace App\Observers;

use App\Models\SolicitudSistema;
use App\Services\WorkflowStateService;

class SolicitudWorkflowObserver
{
    public function updating(SolicitudSistema $solicitud): void
    {
        if (!$solicitud->isDirty('estado')) return;

        app(WorkflowStateService::class)->assertSolicitudTransition(
            (string)$solicitud->getOriginal('estado'),
            (string)$solicitud->estado,
        );
    }
}
