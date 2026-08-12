<?php

namespace Tests\Feature;

use App\Models\{Cliente,Cuestionario,Empresa,PlanViti,SolicitudSistema};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShortCommercialRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_request_can_be_sent_without_answering_the_old_full_questionnaire(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $questionnaire = Cuestionario::query()->where('activo', true)->firstOrFail();
        $client = Cliente::create([
            'nombre'=>'Robert Prueba',
            'telefono'=>'73995501',
            'estado'=>'formulario_en_proceso',
        ]);
        $company = Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-SHORT-FORM',
            'nombre_comercial'=>'Servicio Técnico Prueba',
            'actividad'=>'Servicios técnicos',
            'estado'=>'pendiente_revision',
        ]);
        $plan = PlanViti::create([
            'codigo'=>'personalizado-short-test',
            'nombre'=>'Cotización personalizada',
            'descripcion'=>'Plan para requerimientos que necesitan revisión.',
            'precio_proyecto'=>null,
            'precio_mensual'=>null,
            'precio_anual'=>null,
            'dias_prueba'=>14,
            'modulos'=>[],
            'max_usuarios'=>null,
            'max_aplicaciones'=>null,
            'activo'=>true,
        ]);
        $request = SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'plan_viti_id'=>$plan->id,
            'codigo'=>'SOL-SHORT-FORM',
            'public_token'=>Str::random(48),
            'publico_habilitado'=>true,
            'titulo'=>'Sistema de gestión para servicios técnicos',
            'resumen'=>'Organizar clientes, órdenes y pagos.',
            'estado'=>'borrador',
            'prioridad'=>'normal',
            'forma_pago_preferida'=>'por_definir',
            'acuerdo_comercial_requerido'=>true,
            'acuerdo_comercial_aceptado'=>true,
            'acuerdo_comercial_nombre'=>'Robert Prueba',
            'acuerdo_comercial_fecha'=>now()->toDateString(),
            'declaracion_aceptada'=>true,
            'declaracion_nombre'=>'Robert Prueba',
            'declaracion_fecha'=>now()->toDateString(),
        ]);

        $this->assertSame(0, $request->respuestas()->count());

        $this->postJson('/api/v1/publico/solicitudes/'.$request->public_token.'/enviar')
            ->assertOk();

        $this->assertDatabaseHas('solicitudes_sistema', [
            'id'=>$request->id,
            'estado'=>'en_revision',
        ]);
    }
}
