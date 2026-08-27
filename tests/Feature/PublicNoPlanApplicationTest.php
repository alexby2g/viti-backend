<?php

namespace Tests\Feature;

use App\Models\Cuestionario;
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicNoPlanApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_questionnaire_can_be_submitted_without_a_payment_plan(): void
    {
        $this->seed(CuestionarioSeeder::class);

        $questionnaire = Cuestionario::query()
            ->where('activo', true)
            ->with(['secciones.preguntas'])
            ->latest('id')
            ->firstOrFail();

        $answers = collect($questionnaire->secciones)
            ->flatMap(fn ($section) => $section->preguntas)
            ->filter(function ($question) {
                if (!$question->obligatoria) return false;
                return !in_array((int) $question->numero, [27, 32, 35, 70], true);
            })
            ->map(function ($question) {
                $multiple = str_contains(strtolower((string) $question->tipo), 'multiple');
                return [
                    'pregunta_id' => $question->id,
                    'valor' => $multiple ? ['VITI'] : 'VITI',
                ];
            })->values()->all();

        $payload = [
            'nombre' => 'Evaluación VITI',
            'correo' => 'no-plan-'.uniqid().'@example.test',
            'telefono' => '700000101',
            'whatsapp' => '700000101',
            'ciudad' => 'Trinidad',
            'empresa_nombre' => 'Empresa Evaluación VITI',
            'empresa_actividad' => 'Servicios y operaciones digitales',
            'empresa_telefono' => '700000101',
            'empresa_whatsapp' => '700000101',
            'empresa_ciudad' => 'Trinidad',
            'titulo_sistema' => 'Sistema VITI sin plan',
            'resumen' => 'Evaluación para recomendar posteriormente la solución y el plan adecuados.',
            'declaracion_aceptada' => true,
            'declaracion_nombre' => 'Evaluación VITI',
            'declaracion_fecha' => now()->toDateString(),
            'respuestas' => $answers,
        ];

        $response = $this->postJson('/api/v1/publico/solicitud/evaluacion', $payload)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'en_revision')
            ->assertJsonPath('data.plan', null)
            ->assertJsonPath('data.empresa', 'Empresa Evaluación VITI');

        $this->assertDatabaseHas('solicitudes_sistema', [
            'codigo' => $response->json('data.codigo'),
            'plan_viti_id' => null,
            'estado' => 'en_revision',
        ]);
    }
}
