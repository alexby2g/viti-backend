<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioDocumentationTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_owner_can_upload_private_evidence_and_order_returns_it(): void
    {
        Storage::fake('private_uploads');
        $tenant = $this->createTenant('DOC-A');
        $orderId = $this->order($tenant['company']->id, 'A');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];
        $file = UploadedFile::fake()->createWithContent('antes.png', $this->png());

        $created = $this->actingAs($tenant['user'])
            ->withHeaders($headers)
            ->post('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/evidencias', [
                'archivo' => $file,
                'categoria' => 'antes',
                'descripcion' => 'Estado del evaporador antes del mantenimiento.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.categoria', 'antes')
            ->assertJsonPath('data.orden_id', $orderId)
            ->json('data');

        $stored = DB::table('electrofrio_evidencias')->find($created['id']);
        $this->assertNotNull($stored);
        Storage::disk('private_uploads')->assertExists($stored->ruta);

        $this->actingAs($tenant['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/ordenes-operativas/'.$orderId, $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data.evidencias')
            ->assertJsonPath('data.evidencias.0.nombre_original', 'antes.png');

        $this->actingAs($tenant['user'])
            ->withHeaders($headers)
            ->get('/api/v1/mi/apps/electrofrio/evidencias/'.$created['id'].'/descargar')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_evidence_is_isolated_and_closed_order_evidence_is_immutable(): void
    {
        Storage::fake('private_uploads');
        $first = $this->createTenant('DOC-B1');
        $second = $this->createTenant('DOC-B2');
        $orderId = $this->order($second['company']->id, 'B2');
        $secondHeaders = ['X-VITI-Empresa' => (string) $second['company']->id];

        $created = $this->actingAs($second['user'])
            ->withHeaders($secondHeaders)
            ->post('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/evidencias', [
                'archivo' => UploadedFile::fake()->createWithContent('despues.png', $this->png()),
                'categoria' => 'despues',
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($first['user'])
            ->withHeaders(['X-VITI-Empresa' => (string) $first['company']->id])
            ->get('/api/v1/mi/apps/electrofrio/evidencias/'.$created['id'].'/descargar')
            ->assertNotFound();

        DB::table('electrofrio_ordenes')->where('id', $orderId)->update(['etapa' => 'cerrada', 'finalizada_at' => now()]);

        $this->actingAs($second['user'])
            ->deleteJson('/api/v1/mi/apps/electrofrio/evidencias/'.$created['id'], [], $secondHeaders)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Las evidencias de una orden cerrada forman parte del historial y ya no pueden eliminarse.');

        $this->assertDatabaseHas('electrofrio_evidencias', ['id' => $created['id']]);
    }

    public function test_executable_upload_is_rejected_and_pdf_can_be_generated(): void
    {
        Storage::fake('private_uploads');
        $tenant = $this->createTenant('DOC-C');
        $orderId = $this->order($tenant['company']->id, 'C');
        $headers = ['X-VITI-Empresa' => (string) $tenant['company']->id];

        $this->actingAs($tenant['user'])
            ->withHeaders($headers)
            ->post('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/evidencias', [
                'archivo' => UploadedFile::fake()->create('peligro.exe', 10, 'application/x-msdownload'),
                'categoria' => 'documento',
            ])
            ->assertUnprocessable();

        $this->actingAs($tenant['user'])
            ->withHeaders($headers)
            ->get('/api/v1/mi/apps/electrofrio/ordenes/'.$orderId.'/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    private function order(int $companyId, string $suffix): int
    {
        $now = now();
        $clientId = DB::table('electrofrio_clientes')->insertGetId([
            'empresa_id' => $companyId,
            'nombre' => 'Cliente documentación '.$suffix,
            'telefono' => '7'.substr(str_pad((string) abs(crc32('doc-client-'.$suffix)), 8, '0', STR_PAD_LEFT), 0, 8),
            'direccion' => 'Av. Documento 123',
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $equipmentId = DB::table('electrofrio_equipos')->insertGetId([
            'empresa_id' => $companyId,
            'cliente_id' => $clientId,
            'tipo' => 'Aire acondicionado Split',
            'marca' => 'Samsung',
            'modelo' => 'WindFree',
            'serie' => 'DOC-'.$suffix,
            'capacidad' => '12000 BTU',
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $technicianId = DB::table('electrofrio_tecnicos')->insertGetId([
            'empresa_id' => $companyId,
            'nombre' => 'Técnico documentación '.$suffix,
            'especialidad' => 'Climatización',
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('electrofrio_fichas_tecnicas')->insert([
            'empresa_id' => $companyId,
            'equipo_id' => $equipmentId,
            'gas_refrigerante' => 'R410A',
            'voltaje' => '220V',
            'amperaje_nominal' => 8.50,
            'presion_succion_psi' => 120,
            'presion_descarga_psi' => 350,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = DB::table('electrofrio_ordenes')->insertGetId([
            'empresa_id' => $companyId,
            'codigo' => 'TMP-DOC-'.bin2hex(random_bytes(4)),
            'cliente_id' => $clientId,
            'equipo_id' => $equipmentId,
            'tecnico_id' => $technicianId,
            'fecha_cita' => '2026-08-21',
            'hora_cita' => '10:30',
            'direccion_servicio' => 'Av. Documento 123',
            'problema_reportado' => 'El equipo enfría poco.',
            'tipo_servicio' => 'Mantenimiento preventivo',
            'prioridad' => 'normal',
            'etapa' => 'servicio',
            'decision_cliente' => 'aceptado',
            'diagnostico' => 'Filtros saturados y presión por verificar.',
            'propuesta' => 'Limpieza completa y control de parámetros.',
            'trabajo_realizado' => 'Limpieza y revisión general.',
            'recomendaciones' => 'Realizar mantenimiento cada seis meses.',
            'costo_mano_obra' => 250,
            'costo_materiales' => 0,
            'descuento' => 0,
            'total' => 250,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('electrofrio_ordenes')->where('id', $id)->update(['codigo' => 'EF-DOC-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT)]);
        return $id;
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    }
}
