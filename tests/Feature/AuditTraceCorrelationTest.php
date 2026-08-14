<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTraceCorrelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_event_keeps_browser_and_backend_trace_ids(): void
    {
        Usuario::create([
            'nombre'=>'Prueba Trace',
            'usuario'=>'trace_test',
            'documento'=>'98000111',
            'telefono'=>'78000111',
            'password'=>'Correcta123456',
            'rol'=>'cliente',
            'estado'=>'activo',
        ]);

        $response = $this->withHeader('X-VITI-Client-Request-ID','VITI-CLIENT-audit-test')
            ->postJson('/api/v1/auth/login',[
                'acceso'=>'trace_test',
                'password'=>'Incorrecta123456',
            ])
            ->assertStatus(422);

        $serverRequestId = $response->headers->get('X-VITI-Request-ID');
        $this->assertNotEmpty($serverRequestId);

        $audit = \DB::table('auditoria')->where('accion','inicio_sesion_fallido')->latest('id')->first();
        $this->assertNotNull($audit);
        $data = json_decode((string)$audit->datos,true);
        $this->assertSame($serverRequestId,$data['_trace']['request_id'] ?? null);
        $this->assertSame('VITI-CLIENT-audit-test',$data['_trace']['client_request_id'] ?? null);
    }
}
