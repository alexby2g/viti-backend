<?php

namespace Tests\Feature;

use App\Models\{Aplicacion,CatalogoAplicacion,Cliente,Empresa,PlanViti,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicioTecnicoV1ReadyTest extends TestCase
{
    use RefreshDatabase;

    private function setupBusiness(): array
    {
        $platform = Usuario::create([
            'nombre'=>'Super Admin','usuario'=>'st_v1_admin','documento'=>'95000001','telefono'=>'75000001',
            'password'=>'Prueba1234','rol'=>'superadmin','estado'=>'activo',
        ]);
        $ownerProfile = Cliente::create(['nombre'=>'Laura Propietaria','telefono'=>'75000002','estado'=>'informacion_recibida']);
        $plan = PlanViti::query()->where('codigo','profesional-1950')->firstOrFail();
        $company = Empresa::create([
            'cliente_id'=>$ownerProfile->id,'plan_viti_id'=>$plan->id,'codigo'=>'EMP-ST-V1','nombre_comercial'=>'Soporte Vital V1','estado'=>'activo',
        ]);
        $catalog = CatalogoAplicacion::query()->firstOrCreate(
            ['clave'=>'servicio-tecnico'],
            ['nombre'=>'Servicio Técnico VITI','descripcion'=>'Servicio técnico','icono'=>'computer','tipo'=>'web','ruta_base'=>'/apps/servicio-tecnico','activo'=>true,'solicitable'=>false,'orden'=>30]
        );
        $app = Aplicacion::create([
            'empresa_id'=>$company->id,'catalogo_aplicacion_id'=>$catalog->id,'nombre'=>'Soporte Vital V1','slug'=>'soporte-vital-v1-ready',
            'version'=>'1.0.0','tipo'=>'web','entorno'=>'produccion','estado'=>'activo','acceso_cliente'=>false,
        ]);

        $owner = Usuario::create([
            'cliente_id'=>$ownerProfile->id,'nombre'=>'Laura','usuario'=>'laura_st_v1','documento'=>'95000002','telefono'=>'75000003',
            'password'=>'Prueba1234','rol'=>'cliente','estado'=>'activo',
        ]);
        $employee = Usuario::create([
            'nombre'=>'Técnico Empleado','usuario'=>'tecnico_st_v1','documento'=>'95000003','telefono'=>'75000004',
            'password'=>'Prueba1234','rol'=>'cliente','estado'=>'activo',
        ]);
        $company->usuarios()->syncWithoutDetaching([
            $owner->id=>['rol_negocio'=>'propietario','permisos'=>null,'activo'=>true],
            $employee->id=>['rol_negocio'=>'empleado','permisos'=>null,'activo'=>true],
        ]);

        return [$platform,$company,$app,$owner,$employee];
    }

    public function test_client_route_stays_locked_until_explicit_delivery_and_then_uses_business_roles(): void
    {
        [, $company, $app, $owner, $employee] = $this->setupBusiness();
        $headers = ['X-VITI-Empresa'=>(string)$company->id];

        $this->actingAs($owner)
            ->getJson('/api/v1/mi/apps/servicio-tecnico/estado',$headers)
            ->assertForbidden();

        $app->update(['acceso_cliente'=>true,'entregado_at'=>now()]);

        $this->actingAs($owner)
            ->getJson('/api/v1/mi/apps/servicio-tecnico/estado',$headers)
            ->assertOk()
            ->assertJsonPath('data.aplicacion.version','1.0.0')
            ->assertJsonPath('data.rol','propietario')
            ->assertJsonPath('data.puede_administrar',true);

        $this->actingAs($employee)
            ->getJson('/api/v1/mi/apps/servicio-tecnico/referencias',$headers)
            ->assertOk();

        $this->actingAs($employee)
            ->getJson('/api/v1/mi/apps/servicio-tecnico/pagos',$headers)
            ->assertForbidden();

        $this->actingAs($employee)
            ->postJson('/api/v1/mi/apps/servicio-tecnico/clientes',['nombre'=>'No autorizado'],$headers)
            ->assertForbidden();
    }

    public function test_v1_reports_generate_pdf_and_traceable_order_cannot_be_deleted(): void
    {
        [$platform,$company] = $this->setupBusiness();
        $headers = ['X-VITI-Empresa'=>(string)$company->id];

        $clientId = $this->actingAs($platform)->postJson('/api/v1/apps/servicio-tecnico/clientes',[
            'nombre'=>'Cliente Reporte','telefono'=>'76660001','activo'=>true,
        ],$headers)->assertCreated()->json('data.id');

        $orderId = $this->actingAs($platform)->postJson('/api/v1/apps/servicio-tecnico/ordenes',[
            'cliente_id'=>$clientId,'fecha_recepcion'=>'2026-08-10','prioridad'=>'normal',
            'problema_reportado'=>'Equipo no inicia.','costo_servicio'=>120,'descuento'=>0,
        ],$headers)->assertCreated()->json('data.id');

        $this->actingAs($platform)->putJson('/api/v1/apps/servicio-tecnico/ordenes/'.$orderId,[
            'cliente_id'=>$clientId,'fecha_recepcion'=>'2026-08-10','prioridad'=>'normal',
            'problema_reportado'=>'Equipo no inicia.','diagnostico'=>'Fuente dañada.','propuesta'=>null,
            'costo_servicio'=>120,'descuento'=>0,
        ],$headers)->assertOk()->assertJsonPath('data.estado','diagnostico');

        $this->actingAs($platform)
            ->deleteJson('/api/v1/apps/servicio-tecnico/ordenes/'.$orderId,[],$headers)
            ->assertStatus(422);

        $this->actingAs($platform)
            ->get('/api/v1/apps/servicio-tecnico/ordenes/'.$orderId.'/pdf',$headers)
            ->assertOk()
            ->assertHeader('content-type','application/pdf');

        $this->actingAs($platform)
            ->get('/api/v1/apps/servicio-tecnico/reportes/resumen?desde=2026-08-01&hasta=2026-08-31',$headers)
            ->assertOk()
            ->assertHeader('content-type','application/pdf');
    }
}
