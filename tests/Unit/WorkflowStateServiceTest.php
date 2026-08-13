<?php

namespace Tests\Unit;

use App\Services\WorkflowStateService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class WorkflowStateServiceTest extends TestCase
{
    public function test_solicitud_allows_the_official_forward_flow(): void
    {
        $workflow = new WorkflowStateService();

        $workflow->assertSolicitudTransition('borrador', 'en_revision');
        $workflow->assertSolicitudTransition('en_revision', 'aprobada');
        $workflow->assertSolicitudTransition('aprobada', 'convertida');
        $workflow->assertSolicitudTransition('convertida', 'cerrada');

        $this->addToAssertionCount(4);
    }

    public function test_closed_request_cannot_be_reopened(): void
    {
        $workflow = new WorkflowStateService();

        $this->expectException(ValidationException::class);
        $workflow->assertSolicitudTransition('cerrada', 'en_revision');
    }

    public function test_project_phase_cannot_move_backwards(): void
    {
        $workflow = new WorkflowStateService();

        $this->expectException(ValidationException::class);
        $workflow->assertProyectoPhaseTransition('desarrollo', 'analisis');
    }

    public function test_project_phase_can_enter_and_close_maintenance(): void
    {
        $workflow = new WorkflowStateService();

        $workflow->assertProyectoPhaseTransition('finalizado', 'mantenimiento');
        $workflow->assertProyectoPhaseTransition('mantenimiento', 'finalizado');

        $this->addToAssertionCount(2);
    }

    public function test_cancelled_project_cannot_return_to_active(): void
    {
        $workflow = new WorkflowStateService();

        $this->expectException(ValidationException::class);
        $workflow->assertProyectoStateTransition('cancelado', 'activo');
    }

    public function test_service_exposes_the_canonical_workflow_catalogs(): void
    {
        $workflow = new WorkflowStateService();

        $this->assertSame(
            ['borrador', 'en_revision', 'aprobada', 'rechazada', 'convertida', 'cerrada'],
            $workflow->solicitudStates()
        );
        $this->assertSame(
            ['levantamiento', 'analisis', 'diseno', 'desarrollo', 'beta', 'pruebas', 'ajustes', 'implementacion', 'finalizado', 'mantenimiento'],
            $workflow->proyectoPhases()
        );
        $this->assertSame(
            ['activo', 'pausado', 'finalizado', 'mantenimiento', 'cancelado'],
            $workflow->proyectoStates()
        );
    }
}
