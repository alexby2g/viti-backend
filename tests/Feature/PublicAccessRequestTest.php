<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAccessRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_public_request_creation_is_retired(): void
    {
        $this->postJson('/api/v1/publico/solicitudes', [
            'nombre' => 'María Pérez',
            'correo' => 'maria@example.com',
            'telefono' => '70012345',
            'empresa_nombre' => 'Clima Servicios',
        ])->assertStatus(410);
    }
}
