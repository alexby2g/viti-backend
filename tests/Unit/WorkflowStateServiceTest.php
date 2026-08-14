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
        $workflow->assertSolicitudTransition('borrador','en_revision');
        $workflow->assertSolicitudTransition('en_revision','aprobada');
        $workflow->assertSolicitudTransition('aprobada','convertida');
        $workflow->assertSolicitudTransition('convertida','cerrada');
        $this->addToAssertionCount(4);
    }

    public function test_request_cannot_be_converted_before_approval(): void
    {
        $this->expectException(ValidationException::class);
        (new WorkflowStateService())->assertSolicitudTransition('en_revision','convertida');
    }

    public function test_closed_request_cannot_be_reopened(): void
    {
        $this->expectException(ValidationException::class);
        (new WorkflowStateService())->assertSolicitudTransition('cerrada','en_revision');
    }

    public function test_project_phase_cannot_move_backwards(): void
    {
        $this->expectException(ValidationException::class);
        (new WorkflowStateService())->assertProyectoPhaseTransition('desarrollo','analisis');
    }

    public function test_project_phase_cannot_skip_required_stages(): void
    {
        $this->expectException(ValidationException::class);
        (new WorkflowStateService())->assertProyectoPhaseTransition('levantamiento','implementacion');
    }

    public function test_project_can_skip_adjustments_when_qa_has_no_findings(): void
    {
        (new WorkflowStateService())->assertProyectoPhaseTransition('pruebas','implementacion');
        $this->addToAssertionCount(1);
    }

    public function test_project_phase_can_enter_and_close_maintenance(): void
    {
        $workflow=new WorkflowStateService();
        $workflow->assertProyectoPhaseTransition('finalizado','mantenimiento');
        $workflow->assertProyectoPhaseTransition('mantenimiento','finalizado');
        $this->addToAssertionCount(2);
    }

    public function test_cancelled_project_cannot_return_to_active(): void
    {
        $this->expectException(ValidationException::class);
        (new WorkflowStateService())->assertProyectoStateTransition('cancelado','activo');
    }

    public function test_service_exposes_the_canonical_workflow_catalogs(): void
    {
        $workflow=new WorkflowStateService();
        $this->assertSame(['borrador','en_revision','aprobada','rechazada','convertida','cerrada'],$workflow->solicitudStates());
        $this->assertSame(['levantamiento','analisis','diseno','desarrollo','beta','pruebas','ajustes','implementacion','finalizado','mantenimiento'],$workflow->proyectoPhases());
        $this->assertSame(['activo','pausado','finalizado','mantenimiento','cancelado'],$workflow->proyectoStates());
    }

    public function test_service_exposes_only_next_legal_transitions(): void
    {
        $workflow=new WorkflowStateService();
        $this->assertSame(['aprobada','rechazada','cerrada'],$workflow->nextSolicitudStates('en_revision'));
        $this->assertSame(['pruebas'],$workflow->nextProyectoPhases('beta'));
        $this->assertSame(['ajustes','implementacion'],$workflow->nextProyectoPhases('pruebas'));
        $this->assertSame(['pausado','finalizado','cancelado','mantenimiento'],$workflow->nextProyectoStates('activo'));
        $this->assertSame([],$workflow->nextSolicitudStates('cerrada'));
    }

    public function test_workflow_snapshots_are_frontend_safe_contracts(): void
    {
        $workflow=new WorkflowStateService();
        $request=$workflow->solicitudSnapshot('borrador');
        $project=$workflow->proyectoSnapshot('desarrollo','activo');
        $this->assertSame('borrador',$request['actual']);
        $this->assertContains('en_revision',$request['permitidos']);
        $this->assertSame('desarrollo',$project['fase']['actual']);
        $this->assertSame(['beta'],$project['fase']['permitidos']);
        $this->assertSame('activo',$project['estado']['actual']);
    }
}
