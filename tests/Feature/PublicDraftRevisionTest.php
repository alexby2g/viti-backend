<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicDraftRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_public_draft_save_is_closed(): void
    {
        $this->putJson('/api/v1/publico/solicitudes/demo-token', [
            'base_revision' => 0,
            'registro' => ['titulo_sistema' => 'No debe guardarse'],
        ])->assertStatus(410);
    }

    public function test_retired_public_draft_submission_is_closed(): void
    {
        $this->postJson('/api/v1/publico/solicitudes/demo-token/enviar', [
            'base_revision' => 0,
        ])->assertStatus(410);
    }
}
