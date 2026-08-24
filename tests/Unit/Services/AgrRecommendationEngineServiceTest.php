<?php

namespace Tests\Unit\Services;

use App\Services\AgrRecommendationEngineService;
use PHPUnit\Framework\TestCase;

class AgrRecommendationEngineServiceTest extends TestCase
{
    private function decision(string $value, int $score = 50, int $signals = 3): array
    {
        return [
            'decision' => $value,
            'score' => $score,
            'confidence' => 80,
            'signal_count' => $signals,
        ];
    }

    public function test_high_confidence_intervention_becomes_priority_action(): void
    {
        $result = (new AgrRecommendationEngineService())->recommend(
            $this->decision('intervene', 85),
            ['level' => 'high', 'score' => 92],
            ['level' => 1],
        );

        $this->assertSame('prepare_priority_action', $result['action']);
        $this->assertTrue($result['requires_human_confirmation']);
        $this->assertFalse($result['safe_to_auto_execute']);
    }

    public function test_medium_confidence_review_becomes_review_recommendation(): void
    {
        $result = (new AgrRecommendationEngineService())->recommend(
            $this->decision('review', 45),
            ['level' => 'medium', 'score' => 68],
        );

        $this->assertSame('prepare_review', $result['action']);
        $this->assertTrue($result['requires_human_confirmation']);
    }

    public function test_low_confidence_does_not_recommend_intervention(): void
    {
        $result = (new AgrRecommendationEngineService())->recommend(
            $this->decision('intervene', 90),
            ['level' => 'low', 'score' => 20],
        );

        $this->assertSame('observe', $result['action']);
        $this->assertFalse($result['safe_to_auto_execute']);
    }
}
