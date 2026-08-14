<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_record_cannot_be_updated_through_eloquent(): void
    {
        $audit = Auditoria::create([
            'accion' => 'accion_original',
            'descripcion' => 'Registro de prueba.',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Los registros de auditoría son inmutables.');

        $audit->update(['accion' => 'accion_manipulada']);
    }

    public function test_audit_record_cannot_be_deleted_through_eloquent(): void
    {
        $audit = Auditoria::create([
            'accion' => 'accion_persistente',
            'descripcion' => 'Registro de prueba.',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Los registros de auditoría son inmutables.');

        $audit->delete();
    }
}
