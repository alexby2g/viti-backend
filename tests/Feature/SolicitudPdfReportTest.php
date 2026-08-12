<?php

namespace Tests\Feature;

use App\Http\Controllers\ReporteController;
use App\Models\{Cliente,Cuestionario,Empresa,PlanViti,SolicitudSistema};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SolicitudPdfReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_renders_with_short_flow_answers_and_commercial_agreement(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $questionnaire = Cuestionario::query()->where('activo', true)->firstOrFail();
        $client = Cliente::create([
            'nombre'=>'Robert Prueba',
            'telefono'=>'71545879',
            'estado'=>'informacion_recibida',
        ]);
        $company = Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-PDF-TEST',
            'nombre_comercial'=>'Robert Soluciones',
            'actividad'=>'Servicios de software y hardware',
            'estado'=>'pendiente_revision',
        ]);
        $plan = PlanViti::query()->where('codigo','personalizado')->firstOrFail();
        $request = SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'plan_viti_id'=>$plan->id,
            'codigo'=>'SOL-PDF-TEST',
            'public_token'=>Str::random(48),
            'publico_habilitado'=>true,
            'titulo'=>'Sistema de registro y control técnico',
            'resumen'=>'Organizar clientes, servicios, costos y técnicos.',
            'estado'=>'en_revision',
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

        $questions = $questionnaire->secciones()->with('preguntas')->get()->flatMap->preguntas->take(3)->values();
        $request->respuestas()->create(['pregunta_id'=>$questions[0]->id,'respuesta_texto'=>'Robert Soluciones']);
        $request->respuestas()->create(['pregunta_id'=>$questions[1]->id,'respuesta_json'=>['Propietario','Técnico']]);
        $request->respuestas()->create(['pregunta_id'=>$questions[2]->id,'respuesta_json'=>['grupo'=>['Clientes','Órdenes'],'extra'=>'Pagos']]);

        $response = app(ReporteController::class)->solicitud($request->fresh());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }
}