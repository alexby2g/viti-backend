<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewRequestCommercialAgreementTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_public_submission_route_is_retired(): void
    {
        $this->postJson('/api/v1/publico/solicitudes/demo-token/enviar', [])->assertStatus(410);
    }

    public function test_legacy_public_draft_update_route_is_retired(): void
    {
        $this->putJson('/api/v1/publico/solicitudes/demo-token', [])->assertStatus(410);
    }
}
