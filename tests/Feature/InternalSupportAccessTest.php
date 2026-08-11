<?php

namespace Tests\Feature;

use App\Models\{Cliente,Conversacion,Cuestionario,Empresa,Mantenimiento,Proyecto,SolicitudSistema,Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InternalSupportAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_only_sees_assigned_work_and_cannot_enter_platform_admin(): void
    {
        $this->seed(CuestionarioSeeder::class);
        $questionnaire = Cuestionario::query()->where('activo', true)->firstOrFail();

        $support = Usuario::create([
            'nombre'=>'Soporte Interno',
            'usuario'=>'soporte_interno',
            'documento'=>'88776655',
            'telefono'=>'70001234',
            'password'=>'PruebaSegura123',
            'rol'=>'soporte',
            'estado'=>'activo',
        ]);
        $other = Usuario::create([
            'nombre'=>'Otro Soporte',
            'usuario'=>'otro_soporte',
            'documento'=>'88776656',
            'telefono'=>'70001235',
            'password'=>'PruebaSegura123',
            'rol'=>'soporte',
            'estado'=>'activo',
        ]);
        $client = Cliente::create(['nombre'=>'Cliente Uno','telefono'=>'71110001','estado'=>'informacion_recibida']);
        $company = Empresa::create(['cliente_id'=>$client->id,'codigo'=>'EMP-SOP-1','nombre_comercial'=>'Empresa Soporte','estado'=>'activo']);

        $request = SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'asignado_a'=>$support->id,
            'codigo'=>'SOL-SOP-1',
            'public_token'=>Str::random(48),
            'publico_habilitado'=>false,
            'titulo'=>'Sistema asignado',
            'estado'=>'en_revision',
            'prioridad'=>'normal',
        ]);
        SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'asignado_a'=>$other->id,
            'codigo'=>'SOL-SOP-2',
            'public_token'=>Str::random(48),
            'publico_habilitado'=>false,
            'titulo'=>'Sistema ajeno',
            'estado'=>'en_revision',
            'prioridad'=>'normal',
        ]);

        $project = Proyecto::create([
            'solicitud_id'=>$request->id,
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'responsable_id'=>$support->id,
            'codigo'=>'PRO-SOP-1',
            'nombre'=>'Proyecto asignado',
            'fase'=>'desarrollo',
            'estado'=>'activo',
            'progreso'=>40,
        ]);
        Mantenimiento::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'asignado_a'=>$support->id,
            'codigo'=>'MAN-SOP-1',
            'titulo'=>'Caso asignado',
            'descripcion'=>'Revisar incidencia',
            'tipo'=>'soporte',
            'prioridad'=>'normal',
            'estado'=>'abierto',
        ]);
        $assignedChat = Conversacion::create([
            'cliente_id'=>$client->id,
            'empresa_id'=>$company->id,
            'responsable_usuario_id'=>$support->id,
            'solicitud_id'=>$request->id,
            'proyecto_id'=>$project->id,
            'asunto'=>'Chat asignado',
            'estado'=>'abierta',
            'contexto'=>'viti',
            'canal_principal'=>true,
            'ultimo_mensaje_at'=>now(),
        ]);
        $foreignChat = Conversacion::create([
            'cliente_id'=>$client->id,
            'empresa_id'=>$company->id,
            'responsable_usuario_id'=>$other->id,
            'asunto'=>'Chat ajeno',
            'estado'=>'abierta',
            'contexto'=>'viti',
            'canal_principal'=>true,
            'ultimo_mensaje_at'=>now(),
        ]);

        $overview = $this->actingAs($support)->getJson('/api/v1/soporte/resumen');
        $overview->assertOk()
            ->assertJsonPath('data.resumen.solicitudes', 1)
            ->assertJsonPath('data.resumen.proyectos', 1)
            ->assertJsonPath('data.resumen.casos_abiertos', 1)
            ->assertJsonPath('data.conversaciones.0.id', $assignedChat->id);

        $this->actingAs($support)->getJson('/api/v1/dashboard')->assertForbidden();
        $this->actingAs($support)->getJson('/api/v1/notificaciones/buzon')->assertForbidden();
        $this->actingAs($support)->getJson("/api/v1/soporte/buzon/{$assignedChat->id}")->assertOk();
        $this->actingAs($support)->getJson("/api/v1/soporte/buzon/{$foreignChat->id}")->assertForbidden();
    }

    public function test_support_can_update_only_its_assigned_maintenance(): void
    {
        $support = Usuario::create([
            'nombre'=>'Soporte Uno','usuario'=>'soporte_uno','documento'=>'88770001','telefono'=>'70002001',
            'password'=>'PruebaSegura123','rol'=>'soporte','estado'=>'activo',
        ]);
        $other = Usuario::create([
            'nombre'=>'Soporte Dos','usuario'=>'soporte_dos','documento'=>'88770002','telefono'=>'70002002',
            'password'=>'PruebaSegura123','rol'=>'soporte','estado'=>'activo',
        ]);
        $client = Cliente::create(['nombre'=>'Cliente Dos','telefono'=>'71110002','estado'=>'informacion_recibida']);
        $company = Empresa::create(['cliente_id'=>$client->id,'codigo'=>'EMP-SOP-2','nombre_comercial'=>'Empresa Dos','estado'=>'activo']);
        $own = Mantenimiento::create([
            'empresa_id'=>$company->id,'cliente_id'=>$client->id,'asignado_a'=>$support->id,'codigo'=>'MAN-SOP-2',
            'titulo'=>'Propio','tipo'=>'soporte','prioridad'=>'normal','estado'=>'abierto',
        ]);
        $foreign = Mantenimiento::create([
            'empresa_id'=>$company->id,'cliente_id'=>$client->id,'asignado_a'=>$other->id,'codigo'=>'MAN-SOP-3',
            'titulo'=>'Ajeno','tipo'=>'soporte','prioridad'=>'normal','estado'=>'abierto',
        ]);

        $this->actingAs($support)
            ->putJson("/api/v1/soporte/mantenimientos/{$own->id}/estado", ['estado'=>'en_proceso'])
            ->assertOk()->assertJsonPath('data.estado','en_proceso');

        $this->actingAs($support)
            ->putJson("/api/v1/soporte/mantenimientos/{$foreign->id}/estado", ['estado'=>'en_proceso'])
            ->assertForbidden();
    }
}
