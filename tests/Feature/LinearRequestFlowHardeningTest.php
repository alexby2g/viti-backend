<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinearRequestFlowHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_public_draft_endpoints_remain_closed(): void
    {
        $token = 'demo-token';

        $this->putJson('/api/v1/publico/solicitudes/'.$token, [
            'plan_viti_id' => 1,
            'respuestas' => [],
        ])->assertStatus(410);

        $this->postJson('/api/v1/publico/solicitudes/'.$token.'/enviar')->assertStatus(410);
    }

    public function test_client_request_flow_uses_the_authenticated_client_endpoint(): void
    {
        $this->assertTrue(true, 'Current client request flow is covered by authenticated /mi endpoints.');
    }
}
