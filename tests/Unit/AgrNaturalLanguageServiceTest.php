<?php

namespace Tests\Unit;

use App\Services\AgrNaturalLanguageService;
use Tests\TestCase;

class AgrNaturalLanguageServiceTest extends TestCase
{
    public function test_it_understands_natural_round_requests(): void
    {
        $result = app(AgrNaturalLanguageService::class)->adapt('Bro, haz una ronda completa de VITI y dime qué está mal.');

        $this->assertSame('system_scan', $result['intent']);
        $this->assertGreaterThan(0.7, $result['confidence']);
    }

    public function test_it_detects_explanation_requests(): void
    {
        $result = app(AgrNaturalLanguageService::class)->adapt('¿Por qué me recomiendas esto?');

        $this->assertSame('why_recommendation', $result['intent']);
    }

    public function test_it_extracts_context_subject(): void
    {
        $result = app(AgrNaturalLanguageService::class)->adapt('Revisa Sahory y dime qué está pasando.');

        $this->assertSame('context_lookup', $result['intent']);
        $this->assertSame('sahory y dime qué está pasando.', $result['subject']);
    }

    public function test_unknown_input_remains_safe(): void
    {
        $result = app(AgrNaturalLanguageService::class)->adapt('cuéntame un chiste');

        $this->assertSame('unknown', $result['intent']);
        $this->assertSame(0.0, $result['confidence']);
    }
}
