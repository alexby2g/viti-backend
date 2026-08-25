<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSolicitudOptionalResponsesTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_public_draft_endpoint_rejects_optional_answer_updates(): void
    {
        $this->putJson('/api/v1/publico/solicitudes/demo-token', [
            'respuestas' => [],
            'plan_viti_id' => 1,
        ])->assertStatus(410);
    }
}
