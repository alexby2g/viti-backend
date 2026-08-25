<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyRequestCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_public_request_submission_is_retired(): void
    {
        $this->postJson('/api/v1/publico/solicitudes/demo-token/enviar', [])->assertStatus(410);
    }
}
