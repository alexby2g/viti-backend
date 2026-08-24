<?php

namespace Tests\Unit;

use App\Services\AgrConfidenceEngineService;
use Tests\TestCase;

class AgrConfidenceEngineServiceTest extends TestCase
{
    public function test_high_confidence_pattern_is_recommended(): void
    {
        $service = new AgrConfidenceEngineService();
        $result = $service->evaluate(
            ['id' => 'DEC-1', 'decision' => 'review', 'confidence' => 55, 'recommendation' => 'Revisar solicitudes.'],
            [[
                'key' => 'PAT-1',
                'decision' => 'review',
                'recommendation' => 'Revisar solicitudes.',
                'count' => 12,
                'success_rate' => 91.7,
            ]]
        );

        $this->assertSame('high', $result['level']);
        $this->assertSame('recommend', $result['behavior']);
        $this->assertSame('PAT-1', $result['matched_pattern']);
        $this->assertFalse($result['safe_to_auto_execute']);
    }

    public function test_medium_confidence_requires_review(): void
    {
        $service = new AgrConfidenceEngineService();
        $result = $service->evaluate(
            ['id' => 'DEC-2', 'decision' => 'review', 'confidence' => 45, 'recommendation' => 'Revisar soporte.'],
            [[
                'key' => 'PAT-2',
                'decision' => 'review',
                'recommendation' => 'Revisar soporte.',
                'count' => 5,
                'success_rate' => 72,
            ]]
        );

        $this->assertSame('medium', $result['level']);
        $this->assertSame('recommend_with_review', $result['behavior']);
    }

    public function test_low_confidence_observes_only_without_history(): void
    {
        $service = new AgrConfidenceEngineService();
        $result = $service->evaluate(
            ['id' => 'DEC-3', 'decision' => 'observe', 'confidence' => 15, 'recommendation' => 'Mantener observación.'],
            []
        );

        $this->assertSame('low', $result['level']);
        $this->assertSame('observe_only', $result['behavior']);
        $this->assertNull($result['matched_pattern']);
    }
}
