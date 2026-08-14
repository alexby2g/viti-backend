<?php

namespace Tests\Feature;

use App\Models\{Aplicacion,Cliente,Cuestionario,Empresa,SolicitudSistema,Usuario};
use Database\Seeders\CuestionarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoreGoldenPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_critical_viti_journey_reaches_delivered_application_with_consistent_states(): void
    {
        $this->seed(CuestionarioSeeder::class);

        $admin=Usuario::create([
            'nombre'=>'Admin Golden Path',
            'usuario'=>'admin_golden_path',
            'documento'=>'901122334',
            'telefono'=>'70112233',
            'password'=>'Prueba1234',
            'rol'=>'superadmin',
            'estado'=>'activo',
        ]);
        $client=Cliente::create([
            'nombre'=>'Cliente Golden Path',
            'telefono'=>'69112233',
            'estado'=>'informacion_recibida',
        ]);
        $company=Empresa::create([
            'cliente_id'=>$client->id,
            'codigo'=>'EMP-GOLDEN',
            'nombre_comercial'=>'Empresa Golden Path',
            'actividad'=>'Servicios técnicos',
            'estado'=>'activo',
        ]);
        $questionnaire=Cuestionario::query()->where('activo',true)->firstOrFail();
        $request=SolicitudSistema::create([
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'cuestionario_id'=>$questionnaire->id,
            'codigo'=>'SOL-GOLDEN',
            'public_token'=>Str::random(48),
            'publico_habilitado'=>true,
            'titulo'=>'Solicitud Golden Path',
            'estado'=>'aprobada',
            'prioridad'=>'normal',
            'aprobado_at'=>now(),
        ]);

        $projectResponse=$this->actingAs($admin)->postJson('/api/v1/proyectos',[
            'solicitud_id'=>$request->id,
            'empresa_id'=>$company->id,
            'cliente_id'=>$client->id,
            'nombre'=>'Proyecto Golden Path',
            'fase'=>'levantamiento',
            'estado'=>'activo',
            'progreso'=>0,
        ])->assertCreated();

        $projectId=(int)$projectResponse->json('data.id');
        $this->assertGreaterThan(0,$projectId);
        $this->assertDatabaseHas('solicitudes_sistema',[
            'id'=>$request->id,
            'estado'=>'convertida',
        ]);

        $steps=[
            ['analisis',10,'Análisis inicial'],
            ['diseno',20,'Diseño aprobado'],
            ['desarrollo',40,'Desarrollo iniciado'],
            ['beta',60,'Versión beta'],
            ['pruebas',75,'Pruebas integrales'],
            ['implementacion',90,'Implementación'],
        ];

        foreach($steps as [$phase,$progress,$title]){
            $this->actingAs($admin)->postJson("/api/v1/proyectos/{$projectId}/avances",[
                'fase'=>$phase,
                'area'=>'general',
                'titulo'=>$title,
                'progreso'=>$progress,
                'visible_cliente'=>true,
            ])->assertCreated()->assertJsonPath('workflow.fase.actual',$phase);
        }

        $this->actingAs($admin)->postJson("/api/v1/proyectos/{$projectId}/avances",[
            'fase'=>'finalizado',
            'area'=>'qa',
            'titulo'=>'Entrega técnica final',
            'visible_cliente'=>true,
        ])->assertCreated()
          ->assertJsonPath('workflow.fase.actual','finalizado')
          ->assertJsonPath('workflow.estado.actual','finalizado');

        $this->assertDatabaseHas('proyectos',[
            'id'=>$projectId,
            'fase'=>'finalizado',
            'estado'=>'finalizado',
            'progreso'=>100,
        ]);

        $appResponse=$this->actingAs($admin)->postJson('/api/v1/aplicaciones',[
            'empresa_id'=>$company->id,
            'proyecto_id'=>$projectId,
            'nombre'=>'Aplicación Golden Path',
            'version'=>'1.0.0',
            'tipo'=>'web',
            'entorno'=>'beta',
            'estado'=>'en_pruebas',
            'acceso_cliente'=>false,
        ])->assertCreated();

        $appId=(int)$appResponse->json('data.id');
        $this->assertGreaterThan(0,$appId);

        $this->actingAs($admin)->postJson("/api/v1/aplicaciones/{$appId}/ciclo",[
            'entorno'=>'produccion',
            'estado'=>'activo',
        ])->assertOk()
          ->assertJsonPath('data.entorno','produccion')
          ->assertJsonPath('data.estado','activo');

        $this->actingAs($admin)->postJson("/api/v1/aplicaciones/{$appId}/entregar")
            ->assertOk()
            ->assertJsonPath('data.acceso_cliente',true);

        $app=Aplicacion::query()->findOrFail($appId);
        $this->assertSame('produccion',$app->entorno);
        $this->assertSame('activo',$app->estado);
        $this->assertTrue((bool)$app->acceso_cliente);
        $this->assertNotNull($app->entregado_at);

        $this->assertDatabaseHas('auditoria',['accion'=>'proyecto_creado','entidad_id'=>$projectId]);
        $this->assertDatabaseHas('auditoria',['accion'=>'aplicacion_integrada','entidad_id'=>$appId]);
        $this->assertDatabaseHas('auditoria',['accion'=>'aplicacion_entregada','entidad_id'=>$appId]);
    }
}
