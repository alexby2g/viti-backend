<?php

namespace Tests\Unit;

use App\Services\AgrExplanationEngineService;
use Tests\TestCase;

class AgrExplanationEngineServiceTest extends TestCase
{
    public function test_explanation_contains_evidence_risk_and_authorization(): void
    {
        $service = new AgrExplanationEngineService();
        $result = $service->explain(
            ['recommendation' => 'Preparar revisión de la solicitud.', 'action' => 'prepare_review'],
            ['score' => 62, 'signals' => [
                ['source' => 'incident', 'message' => 'Solicitud aprobada sin proyecto.'],
            ]],
            ['score' => 62, 'level' => 'medium'],
        );

        $this->assertSame('Por qué AGR recomienda esto', $result['title']);
        $this->assertSame(62, $result['confidence']['score']);
        $this->assertNotEmpty($result['reasons']);
        $this->assertNotEmpty($result['risk_if_ignored']);
        $this->assertTrue($result['authorization']['requires_human_confirmation']);
        $this->assertFalse($result['authorization']['safe_to_auto_execute']);
    }

    public function test_low_confidence_observation_is_explained_as_non_intervention(): void
    {
        $service = new AgrExplanationEngineService();
        $result = $service->explain(
            ['recommendation' => 'Mantener observación.', 'action' => 'observe'],
            ['score' => 18, 'signals' => []],
            ['score' => 18, 'level' => 'low'],
        );

        $this->assertSame('low', $result['confidence']['level']);
        $this->assertStringContainsString('evidencia suficiente', $result['risk_if_ignored']);
        $this->assertSame('observe', $result['proposed_action']);
    }
}
