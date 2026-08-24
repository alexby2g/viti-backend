<?php

namespace Tests\Unit;

use App\Services\AgrLearningMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgrLearningMemoryServiceTest extends TestCase
{
    public function test_records_and_updates_a_decision_outcome(): void
    {
        $service = new AgrLearningMemoryService();
        $record = $service->recordDecision([
            'id' => 'DEC-TEST',
            'decision' => 'review',
            'score' => 40,
            'confidence' => 75,
            'recommendation' => 'Revisar solicitudes.',
        ]);

        $this->assertSame('pending', $record['outcome']);

        $updated = $service->updateOutcome($record['id'], 'resolved', 'El problema quedó solucionado.');

        $this->assertNotNull($updated);
        $this->assertSame('resolved', $updated['outcome']);
        $this->assertSame('El problema quedó solucionado.', $updated['outcome_note']);
    }

    public function test_builds_pattern_success_rate_from_outcomes(): void
    {
        $service = new AgrLearningMemoryService();
        $decision = [
            'id' => 'DEC-PATTERN',
            'decision' => 'review',
            'score' => 35,
            'confidence' => 80,
            'recommendation' => 'Revisar flujo.',
        ];

        $first = $service->recordDecision($decision);
        $second = $service->recordDecision($decision);
        $service->updateOutcome($first['id'], 'resolved');
        $service->updateOutcome($second['id'], 'rejected');

        $pattern = collect($service->patterns())->firstWhere('decision', 'review');

        $this->assertNotNull($pattern);
        $this->assertSame(2, $pattern['count']);
        $this->assertSame(50.0, $pattern['success_rate']);
    }
}
