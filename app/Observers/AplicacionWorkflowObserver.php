<?php

namespace App\Observers;

use App\Models\Aplicacion;
use App\Services\ApplicationWorkflowStateService;

class AplicacionWorkflowObserver
{
    public function updating(Aplicacion $aplicacion): void
    {
        if (!$aplicacion->isDirty('entorno') && !$aplicacion->isDirty('estado')) return;

        app(ApplicationWorkflowStateService::class)->assertTransition(
            (string)$aplicacion->getOriginal('entorno'),
            (string)$aplicacion->entorno,
            (string)$aplicacion->getOriginal('estado'),
            (string)$aplicacion->estado,
        );
    }
}
