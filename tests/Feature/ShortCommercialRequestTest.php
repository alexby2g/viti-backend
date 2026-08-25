<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShortCommercialRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_short_public_request_submission_is_retired(): void
    {
        $this->postJson('/api/v1/publico/solicitudes/demo-token/enviar', [])->assertStatus(410);
    }
}
