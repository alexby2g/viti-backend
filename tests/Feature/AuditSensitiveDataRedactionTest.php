<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AuditSensitiveDataRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_metadata_is_redacted_recursively_before_persisting(): void
    {
        $request = Request::create('/audit-test', 'POST');
        $request->attributes->set('viti_request_id', 'req-123');

        Audit::log($request, 'prueba_redaccion', null, 'Prueba de seguridad.', [
            'password' => 'NoDebePersistir123',
            'normal' => 'dato-visible',
            'nested' => [
                'access_token' => 'token-secreto',
                'client-secret' => 'secreto-cliente',
                'token_id' => 44,
                'dispositivo_hash' => 'hash-visible',
            ],
        ]);

        $audit = Auditoria::query()->where('accion', 'prueba_redaccion')->firstOrFail();

        $this->assertSame('[REDACTADO]', $audit->datos['password']);
        $this->assertSame('[REDACTADO]', $audit->datos['nested']['access_token']);
        $this->assertSame('[REDACTADO]', $audit->datos['nested']['client-secret']);
        $this->assertSame('dato-visible', $audit->datos['normal']);
        $this->assertSame(44, $audit->datos['nested']['token_id']);
        $this->assertSame('hash-visible', $audit->datos['nested']['dispositivo_hash']);
        $this->assertSame('req-123', $audit->datos['_trace']['request_id']);

        $serialized = json_encode($audit->datos, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('NoDebePersistir123', $serialized);
        $this->assertStringNotContainsString('token-secreto', $serialized);
        $this->assertStringNotContainsString('secreto-cliente', $serialized);
    }
}
