<?php

namespace Tests\Feature;

use App\Models\{Cliente,Cuestionario,Empresa,SolicitudSistema};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyRequestCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_request_does_not_require_new_commercial_agreement(): void
    {
        $questionnaire = Cuestionario::query()->where('activo', true)->firstOrFail();
        $client = Cliente::create([
            'nombre'=>'Cliente antiguo',
            'telefono'=>'73990002',
            'estado'=>'formulario_en_proceso',
        ]);
        $company = Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-LEGACY-TEST',
            'nombre_comercial'=>'Empresa Antigua',
            'estado'=>'pendiente_revision',
        ]);
        $request = SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'codigo'=>'SOL-LEGACY-TEST',
            'public_token'=>Str::random(48),
            'publico_habilitado'=>true,
            'titulo'=>'Solicitud antigua',
            'estado'=>'borrador',
            'prioridad'=>'normal',
            'acuerdo_comercial_requerido'=>false,
            'declaracion_aceptada'=>true,
            'declaracion_nombre'=>'Cliente antiguo',
            'declaracion_fecha'=>now()->toDateString(),
        ]);

        foreach ($questionnaire->secciones()->with('preguntas')->get()->flatMap->preguntas->where('obligatoria', true) as $question) {
            $request->respuestas()->create([
                'pregunta_id'=>$question->id,
                'respuesta_texto'=>'Respuesta de compatibilidad',
            ]);
        }

        $this->postJson('/api/v1/publico/solicitudes/'.$request->public_token.'/enviar')
            ->assertOk();

        $this->assertDatabaseHas('solicitudes_sistema', [
            'id'=>$request->id,
            'estado'=>'en_revision',
            'acuerdo_comercial_requerido'=>false,
        ]);
    }
}
