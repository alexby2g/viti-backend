<?php

namespace Tests\Feature;

use App\Models\{Cliente,Empresa,PlanViti,SolicitudSistema,Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequestCommercialAgreementTest extends TestCase
{
    use RefreshDatabase;

    public function test_converting_request_assigns_selected_plan_to_company(): void
    {
        $admin = Usuario::create([
            'nombre'=>'Administrador',
            'usuario'=>'admin_comercial',
            'documento'=>'99112233',
            'telefono'=>'70001122',
            'password'=>'Prueba1234',
            'rol'=>'superadmin',
            'estado'=>'activo',
        ]);
        $client = Cliente::create([
            'nombre'=>'Laura Prueba',
            'telefono'=>'73990001',
            'estado'=>'informacion_recibida',
        ]);
        $company = Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-COMERCIAL-TEST',
            'nombre_comercial'=>'Soporte Vital Test',
            'estado'=>'pendiente_revision',
        ]);
        $plan = PlanViti::create([
            'codigo'=>'plan-test-comercial',
            'nombre'=>'Plan Técnico de prueba',
            'descripcion'=>'Plan para verificar la conversión comercial.',
            'precio_proyecto'=>1900,
            'modulos'=>['inicio','agenda','ordenes','clientes','equipos','tecnicos','pagos','historial','buzon'],
            'max_aplicaciones'=>1,
            'activo'=>true,
        ]);
        $request = SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'plan_viti_id'=>$plan->id,
            'codigo'=>'SOL-COMERCIAL-TEST',
            'public_token'=>Str::random(48),
            'publico_habilitado'=>true,
            'titulo'=>'Sistema para soporte técnico de computadoras',
            'estado'=>'en_revision',
            'prioridad'=>'normal',
            'forma_pago_preferida'=>'50_50',
            'acuerdo_comercial_requerido'=>true,
            'acuerdo_comercial_aceptado'=>true,
            'acuerdo_comercial_nombre'=>'Laura Prueba',
            'acuerdo_comercial_fecha'=>now()->toDateString(),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/proyectos', [
                'solicitud_id'=>$request->id,
                'empresa_id'=>$company->id,
                'cliente_id'=>$client->id,
                'nombre'=>'Proyecto Soporte Vital Test',
                'descripcion'=>'Proyecto creado desde solicitud con plan preferido.',
                'fase'=>'levantamiento',
                'estado'=>'activo',
                'progreso'=>0,
                'fecha_inicio'=>now()->toDateString(),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('empresas', [
            'id'=>$company->id,
            'plan_viti_id'=>$plan->id,
        ]);
        $this->assertDatabaseHas('solicitudes_sistema', [
            'id'=>$request->id,
            'estado'=>'convertida',
        ]);
    }
}
