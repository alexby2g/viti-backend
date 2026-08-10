<?php

namespace Tests\Feature;

use App\Models\{Aplicacion,CatalogoAplicacion,Cliente,Empresa,PlanViti,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServicioTecnicoWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function setupBusiness(): array
    {
        $admin = Usuario::create([
            'nombre'=>'Admin Servicio Técnico','usuario'=>'admin_servicio_tecnico','documento'=>'94000001','telefono'=>'74000001',
            'password'=>'Prueba1234','rol'=>'superadmin','estado'=>'activo',
        ]);
        $owner = Cliente::create(['nombre'=>'Laura Técnica','telefono'=>'74000002','estado'=>'informacion_recibida']);
        $plan = PlanViti::query()->where('codigo','profesional-1950')->firstOrFail();
        $company = Empresa::create([
            'cliente_id'=>$owner->id,'plan_viti_id'=>$plan->id,'codigo'=>'EMP-ST-TEST','nombre_comercial'=>'Soporte Vital Test','estado'=>'activo',
        ]);
        $catalog = CatalogoAplicacion::query()->firstOrCreate(
            ['clave'=>'servicio-tecnico'],
            ['nombre'=>'Servicio Técnico VITI','descripcion'=>'Servicio técnico','icono'=>'computer','tipo'=>'web','ruta_base'=>'/apps/servicio-tecnico','activo'=>true,'solicitable'=>false,'orden'=>30]
        );
        Aplicacion::create([
            'empresa_id'=>$company->id,'catalogo_aplicacion_id'=>$catalog->id,'nombre'=>'Soporte Vital Test','slug'=>'soporte-vital-workflow-test',
            'version'=>'0.8.0','tipo'=>'web','entorno'=>'beta','estado'=>'en_pruebas','acceso_cliente'=>false,
        ]);
        return [$admin,$company];
    }

    public function test_complete_repair_flow_persists_and_is_tenant_scoped(): void
    {
        [$admin,$company] = $this->setupBusiness();
        $headers = ['X-VITI-Empresa'=>(string)$company->id];

        $clientResponse = $this->actingAs($admin)->postJson('/api/v1/apps/servicio-tecnico/clientes',[
            'nombre'=>'Carlos Cliente','telefono'=>'75550001','whatsapp'=>'75550001','direccion'=>'Trinidad','activo'=>true,
        ],$headers)->assertCreated();
        $clientId = $clientResponse->json('data.id');

        $equipmentResponse = $this->actingAs($admin)->postJson('/api/v1/apps/servicio-tecnico/equipos',[
            'cliente_id'=>$clientId,'tipo'=>'Laptop','marca'=>'Lenovo','modelo'=>'ThinkPad','serie'=>'SER-001',
            'especificaciones'=>'16 GB RAM, SSD 512 GB','accesorios_recibidos'=>'Cargador','estado_recepcion'=>'Tapa con rayón leve','activo'=>true,
        ],$headers)->assertCreated();
        $equipmentId = $equipmentResponse->json('data.id');

        $technicianResponse = $this->actingAs($admin)->postJson('/api/v1/apps/servicio-tecnico/tecnicos',[
            'nombre'=>'Técnico Uno','telefono'=>'75550002','especialidad'=>'Hardware y software','activo'=>true,
        ],$headers)->assertCreated();
        $technicianId = $technicianResponse->json('data.id');

        $orderResponse = $this->actingAs($admin)->postJson('/api/v1/apps/servicio-tecnico/ordenes',[
            'cliente_id'=>$clientId,'equipo_id'=>$equipmentId,'tecnico_id'=>$technicianId,
            'fecha_recepcion'=>'2026-08-10','fecha_programada'=>'2026-08-11','hora_programada'=>'10:00','prioridad'=>'normal',
            'problema_reportado'=>'No enciende y el cliente solicita revisión completa.','costo_servicio'=>300,'descuento'=>0,
        ],$headers)->assertCreated();
        $orderId = $orderResponse->json('data.id');

        $this->actingAs($admin)->putJson('/api/v1/apps/servicio-tecnico/ordenes/'.$orderId,[
            'cliente_id'=>$clientId,'equipo_id'=>$equipmentId,'tecnico_id'=>$technicianId,
            'fecha_recepcion'=>'2026-08-10','fecha_programada'=>'2026-08-11','hora_programada'=>'10:00','prioridad'=>'normal',
            'problema_reportado'=>'No enciende y el cliente solicita revisión completa.',
            'diagnostico'=>'Falla en circuito de alimentación.','propuesta'=>'Reparar circuito y realizar pruebas de estabilidad.',
            'costo_servicio'=>300,'descuento'=>0,
        ],$headers)->assertOk()->assertJsonPath('data.estado','esperando_aprobacion');

        $this->actingAs($admin)->postJson('/api/v1/apps/servicio-tecnico/ordenes/'.$orderId.'/decision',[
            'decision'=>'aceptado',
        ],$headers)->assertOk()->assertJsonPath('data.estado','reparacion');

        $this->actingAs($admin)->postJson('/api/v1/apps/servicio-tecnico/ordenes/'.$orderId.'/finalizar-trabajo',[
            'trabajo_realizado'=>'Se reparó el circuito y se realizó limpieza interna.','recomendaciones'=>'Usar protector de voltaje.',
            'garantia_dias'=>30,'condiciones_garantia'=>'Garantía sobre la reparación realizada.',
        ],$headers)->assertOk()->assertJsonPath('data.estado','pruebas');

        $this->actingAs($admin)->postJson('/api/v1/apps/servicio-tecnico/ordenes/'.$orderId.'/pagos',[
            'monto'=>150,'metodo'=>'qr','referencia'=>'QR-TEST-001',
        ],$headers)->assertCreated();

        Storage::fake('private_uploads');
        $this->actingAs($admin)->post('/api/v1/apps/servicio-tecnico/ordenes/'.$orderId.'/evidencias',[
            'archivo'=>UploadedFile::fake()->image('recepcion.jpg',800,600),
            'etapa'=>'recepcion','descripcion'=>'Estado del equipo al recibirlo.',
        ],array_merge($headers,['Accept'=>'application/json']))->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/apps/servicio-tecnico/ordenes/'.$orderId.'/estado',['estado'=>'listo_entrega'],$headers)
            ->assertOk()->assertJsonPath('data.estado','listo_entrega');
        $this->actingAs($admin)->postJson('/api/v1/apps/servicio-tecnico/ordenes/'.$orderId.'/estado',['estado'=>'entregado'],$headers)
            ->assertOk()->assertJsonPath('data.estado','entregado');

        $this->actingAs($admin)->getJson('/api/v1/apps/servicio-tecnico/historial',$headers)
            ->assertOk()->assertJsonPath('data.0.id',$orderId)->assertJsonPath('data.0.saldo',150);

        $this->assertDatabaseHas('servicio_tecnico_equipos',['id'=>$equipmentId,'empresa_id'=>$company->id,'serie'=>'SER-001']);
        $this->assertDatabaseHas('servicio_tecnico_pagos',['orden_id'=>$orderId,'empresa_id'=>$company->id,'monto'=>150]);
        $this->assertDatabaseHas('servicio_tecnico_evidencias',['orden_id'=>$orderId,'empresa_id'=>$company->id,'etapa'=>'recepcion']);
    }

    public function test_unrelated_company_cannot_use_service_technical_app(): void
    {
        [$admin] = $this->setupBusiness();
        $owner = Cliente::create(['nombre'=>'Otro Cliente','telefono'=>'74000012','estado'=>'informacion_recibida']);
        $other = Empresa::create(['cliente_id'=>$owner->id,'codigo'=>'EMP-ST-OTHER','nombre_comercial'=>'Empresa sin app','estado'=>'activo']);

        $this->actingAs($admin)
            ->getJson('/api/v1/apps/servicio-tecnico/resumen',['X-VITI-Empresa'=>(string)$other->id])
            ->assertNotFound();
    }
}
