<?php

namespace App\Http\Controllers;

use App\Models\{AlertaSaas,Empresa,Proyecto,ProyectoAvance,ProyectoAmbiente,ProyectoDominio,ProyectoMiembro,ProyectoRepositorio,SolicitudSistema};
use App\Services\WorkflowStateService;
use App\Support\{Audit,Code,FirebasePush};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProyectoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query=Proyecto::with(['empresa:id,nombre_comercial','cliente:id,nombre,telefono','responsable:id,nombre,apellido'])->latest();
        if($request->filled('fase'))$query->where('fase',$request->string('fase'));
        if($request->filled('buscar')){$term='%'.$request->string('buscar').'%';$query->where(fn($q)=>$q->where('codigo','like',$term)->orWhere('nombre','like',$term)->orWhereHas('empresa',fn($e)=>$e->where('nombre_comercial','like',$term)));}
        return response()->json($query->paginate(min(max((int)$request->input('per_page',20),1),100)));
    }

    public function store(Request $request): JsonResponse
    {
        // En el flujo actual todo proyecto nace de una solicitud aprobada.
        // VITI toma empresa y responsable de esa solicitud para evitar vínculos manuales incorrectos.
        $gate = $request->validate([
            'solicitud_id' => ['required','integer','exists:solicitudes_sistema,id',Rule::unique('proyectos','solicitud_id')],
        ]);
        $source = SolicitudSistema::with(['cliente.usuario'])->findOrFail($gate['solicitud_id']);
        abort_unless($source->estado === 'aprobada',422,'La solicitud debe estar aprobada antes de iniciar el proyecto.');
        abort_unless($source->cliente?->usuario,422,'El responsable debe crear su cuenta VITI desde la invitación antes de iniciar el proyecto.');
        abort_unless($source->empresa_id && $source->cliente_id,422,'La solicitud aprobada debe tener empresa y responsable asociados.');

        $request->merge([
            'empresa_id' => $source->empresa_id,
            'cliente_id' => $source->cliente_id,
            'nombre' => $request->input('nombre') ?: $source->titulo,
            'descripcion' => $request->input('descripcion') ?: $source->resumen,
        ]);

        $data=$this->validateData($request);
        $data['fase']=$data['fase']??'levantamiento';
        $data['estado']=$data['estado']??'activo';
        $data['progreso']=$data['progreso']??0;
        $this->assertCompanyClient((int)$data['empresa_id'],(int)$data['cliente_id']);
        app(WorkflowStateService::class)->assertProyectoIntegrity(null,null,$data['fase'],$data['estado'],(int)$data['progreso']);

        $proyecto=DB::transaction(function()use($data,$request){
            if(!empty($data['solicitud_id'])){
                $solicitud=SolicitudSistema::with(['planViti','cliente.usuario'])->lockForUpdate()->findOrFail($data['solicitud_id']);
                abort_unless((int)$solicitud->empresa_id === (int)$data['empresa_id'] && (int)$solicitud->cliente_id === (int)$data['cliente_id'],422,'La solicitud no corresponde a la empresa o responsable seleccionados.');
                abort_unless($solicitud->estado === 'aprobada',422,'La solicitud debe estar aprobada antes de convertirse en proyecto.');
                abort_unless($solicitud->cliente?->usuario,422,'El responsable debe crear su cuenta VITI desde la invitación antes de iniciar el proyecto.');
                app(WorkflowStateService::class)->assertSolicitudTransition($solicitud->estado,'convertida');
            }

            $p=Proyecto::create($data+['codigo'=>Code::next('proyectos','PRO')]);

            if(!empty($data['solicitud_id'])){
                if($solicitud->plan_viti_id){
                    $solicitud->empresa()->update(['plan_viti_id'=>$solicitud->plan_viti_id,'estado'=>'levantamiento']);
                }
                $solicitud->update(['estado'=>'convertida','aprobado_at'=>$solicitud->aprobado_at ?: now()]);
            }

            ProyectoAvance::create(['proyecto_id'=>$p->id,'creado_por'=>$request->user()->id,'fase'=>$p->fase,'titulo'=>'Proyecto creado','descripcion'=>'Se inició el proyecto en VITI.','progreso'=>$p->progreso]);
            return $p;
        });
        Audit::log($request,'proyecto_creado',$proyecto,'Se creó un proyecto de desarrollo.');
        $proyecto->loadMissing(['empresa','cliente.usuario']);
        if ($proyecto->cliente?->usuario?->id) {
            $clientUserId = $proyecto->cliente->usuario->id;
            AlertaSaas::firstOrCreate(
                ['usuario_id'=>$clientUserId,'clave'=>'proyecto_iniciado_'.$proyecto->id],
                [
                    'empresa_id'=>$proyecto->empresa_id,
                    'tipo'=>'proyecto',
                    'titulo'=>'Tu proyecto ya inició',
                    'mensaje'=>$proyecto->nombre.' ya está en seguimiento dentro de VITI.',
                    'ruta'=>'/mi-proyecto',
                ]
            );
            FirebasePush::sendToUsers([$clientUserId], 'Tu proyecto ya inició', $proyecto->nombre.' ya está en seguimiento dentro de VITI.', [
                'type'=>'proyecto',
                'proyecto_id'=>$proyecto->id,
                'path'=>'/mi-proyecto',
            ]);
        }
        return response()->json(['data'=>$this->withWorkflow($proyecto->load(['empresa','cliente']))],201);
    }

    public function show(Proyecto $proyecto): JsonResponse
    {
        $proyecto->load([
            'empresa','cliente','solicitud.planViti','responsable','avances.creador','avances.archivos','aplicacion','archivos',
            'repositorios','miembros.usuario','ambientes.dominios','dominios',
        ]);
        return response()->json(['data'=>$this->withWorkflow($proyecto)]);
    }

    public function update(Request $request, Proyecto $proyecto): JsonResponse
    {
        $data=$this->validateData($request,$proyecto);
        $this->assertStructuralLinks($proyecto,$data);
        $this->assertCompanyClient((int)$proyecto->empresa_id,(int)$proyecto->cliente_id);

        $workflow=app(WorkflowStateService::class);
        $targetPhase=$data['fase']??$proyecto->fase;
        $targetState=$data['estado']??$proyecto->estado;
        $targetProgress=(int)($data['progreso']??$proyecto->progreso);
        $workflow->assertProyectoPhaseTransition($proyecto->fase,$targetPhase);
        $workflow->assertProyectoStateTransition($proyecto->estado,$targetState);
        $workflow->assertProyectoIntegrity($proyecto->fase,$proyecto->estado,$targetPhase,$targetState,$targetProgress);

        $developmentPresent=$request->has('development');

        DB::transaction(function () use ($proyecto,$data,$request,$developmentPresent): void {
            $proyecto->update($data);
            if ($developmentPresent) $this->syncDevelopment($proyecto->fresh(), $request);
        });

        Audit::log($request,'proyecto_actualizado',$proyecto,'Se actualizó el proyecto y, cuando correspondía, su configuración de desarrollo.');
        return response()->json(['data'=>$this->withWorkflow($proyecto->fresh()->load([
            'empresa','cliente','repositorios','miembros.usuario','ambientes.dominios','dominios'
        ]))]);
    }

    public function addProgress(Request $request, Proyecto $proyecto): JsonResponse
    {
        $data=$request->validate([
            'fase'=>['required',Rule::in($this->phases())],
            'area'=>['nullable',Rule::in(['general','analisis','diseno','frontend','backend','movil','infraestructura','qa'])],
            'titulo'=>['required','string','max:200'],
            'descripcion'=>['nullable','string','max:5000'],
            'progreso'=>['nullable','integer','min:0','max:100'],
            'visible_cliente'=>['nullable','boolean'],
        ]);

        $workflow=app(WorkflowStateService::class);
        $targetState=match($data['fase']){
            'finalizado'=>'finalizado',
            'mantenimiento'=>'mantenimiento',
            default=>$proyecto->estado,
        };
        $targetProgress=$data['fase']==='finalizado'
            ? 100
            : (int)($data['progreso']??$proyecto->progreso);

        $workflow->assertProyectoPhaseTransition($proyecto->fase,$data['fase']);
        $workflow->assertProyectoStateTransition($proyecto->estado,$targetState);
        $workflow->assertProyectoIntegrity($proyecto->fase,$proyecto->estado,$data['fase'],$targetState,$targetProgress);

        $data['progreso']=$targetProgress;
        $avance=ProyectoAvance::create($data+['proyecto_id'=>$proyecto->id,'creado_por'=>$request->user()->id]);
        $proyecto->update([
            'fase'=>$data['fase'],
            'estado'=>$targetState,
            'progreso'=>$targetProgress,
        ]);
        Audit::log($request,'avance_registrado',$proyecto,'Se registró un avance del proyecto.');
        return response()->json(['data'=>$avance,'workflow'=>$workflow->proyectoSnapshot($proyecto->fase,$proyecto->estado)],201);
    }

    public function destroy(Request $request, Proyecto $proyecto): JsonResponse
    {
        abort_if($proyecto->aplicacion()->exists(),422,'No se puede eliminar un proyecto con aplicación integrada.');
        abort_if($proyecto->solicitud_id,422,'No se puede eliminar un proyecto originado en una solicitud convertida. Cancélalo o ciérralo para conservar la trazabilidad.');
        $proyecto->delete(); Audit::log($request,'proyecto_eliminado',$proyecto,'Proyecto enviado a papelera.');
        return response()->json(status:204);
    }

    private function validateData(Request $request,?Proyecto $proyecto=null):array
    {
        $workflow=app(WorkflowStateService::class);
        return $request->validate([
            'solicitud_id'=>['nullable','integer','exists:solicitudes_sistema,id',Rule::unique('proyectos','solicitud_id')->ignore($proyecto?->id)],
            'empresa_id'=>['required','integer','exists:empresas,id'],
            'cliente_id'=>['required','integer','exists:clientes,id'],
            'responsable_id'=>['nullable','integer','exists:usuarios,id'],
            'nombre'=>['required','string','max:200'],
            'descripcion'=>['nullable','string','max:5000'],
            'fase'=>['nullable',Rule::in($workflow->proyectoPhases())],
            'estado'=>['nullable',Rule::in($workflow->proyectoStates())],
            'progreso'=>['nullable','integer','min:0','max:100'],
            'fecha_inicio'=>['nullable','date'],'fecha_beta'=>['nullable','date'],'fecha_entrega'=>['nullable','date'],
            'repositorio_url'=>['nullable','url','max:255'],'produccion_url'=>['nullable','url','max:255'],'observaciones'=>['nullable','string','max:5000'],
        ]);
    }

    private function syncDevelopment(Proyecto $proyecto, Request $request): void
    {
        $development=$request->validate([
            'development'=>['required','array'],
            'development.repositorios'=>['sometimes','array'],
            'development.repositorios.*.tipo'=>['required','string',Rule::in(['frontend','backend','mobile','infra','otro'])],
            'development.repositorios.*.proveedor'=>['required','string',Rule::in(['github','gitlab','bitbucket','otro'])],
            'development.repositorios.*.nombre'=>['required','string','max:180'],
            'development.repositorios.*.url'=>['required','url','max:500'],
            'development.repositorios.*.rama_principal'=>['nullable','string','max:120'],
            'development.repositorios.*.privado'=>['nullable','boolean'],
            'development.repositorios.*.descripcion'=>['nullable','string','max:2000'],

            'development.miembros'=>['sometimes','array'],
            'development.miembros.*.usuario_id'=>['required','integer','exists:usuarios,id'],
            'development.miembros.*.rol'=>['required','string',Rule::in(['responsable','desarrollador','qa','devops','diseno','cliente_lector'])],
            'development.miembros.*.permisos'=>['nullable','array'],
            'development.miembros.*.activo'=>['nullable','boolean'],

            'development.ambientes'=>['sometimes','array'],
            'development.ambientes.*.tipo'=>['required','string',Rule::in(['desarrollo','staging','produccion'])],
            'development.ambientes.*.nombre'=>['required','string','max:120'],
            'development.ambientes.*.frontend_url'=>['nullable','url','max:500'],
            'development.ambientes.*.backend_url'=>['nullable','url','max:500'],
            'development.ambientes.*.proveedor_frontend'=>['nullable','string','max:60'],
            'development.ambientes.*.proveedor_backend'=>['nullable','string','max:60'],
            'development.ambientes.*.base_datos_referencia'=>['nullable','string','max:180'],
            'development.ambientes.*.estado'=>['nullable','string',Rule::in(['pendiente','en_pruebas','estable','bloqueado','activo'])],
            'development.ambientes.*.notas'=>['nullable','string','max:3000'],

            'development.dominios'=>['sometimes','array'],
            'development.dominios.*.ambiente_tipo'=>['nullable','string',Rule::in(['desarrollo','staging','produccion'])],
            'development.dominios.*.dominio'=>['required','string','max:255'],
            'development.dominios.*.tipo'=>['nullable','string',Rule::in(['web','api','staging','otro'])],
            'development.dominios.*.estado'=>['nullable','string',Rule::in(['pendiente','configurando','activo','bloqueado'])],
            'development.dominios.*.verificado_at'=>['nullable','date'],
            'development.dominios.*.notas'=>['nullable','string','max:2000'],
        ]);

        $payload=$development['development'];

        $proyecto->dominios()->delete();
        $proyecto->ambientes()->delete();
        $proyecto->miembros()->delete();
        $proyecto->repositorios()->delete();

        foreach (($payload['repositorios'] ?? []) as $item) {
            ProyectoRepositorio::create([
                'proyecto_id'=>$proyecto->id,
                'tipo'=>$item['tipo'],
                'proveedor'=>$item['proveedor'],
                'nombre'=>$item['nombre'],
                'url'=>$item['url'],
                'rama_principal'=>$item['rama_principal'] ?? 'main',
                'privado'=>(bool)($item['privado'] ?? true),
                'descripcion'=>$item['descripcion'] ?? null,
            ]);
        }

        foreach (($payload['miembros'] ?? []) as $item) {
            ProyectoMiembro::create([
                'proyecto_id'=>$proyecto->id,
                'usuario_id'=>$item['usuario_id'],
                'rol'=>$item['rol'],
                'permisos'=>$item['permisos'] ?? null,
                'activo'=>(bool)($item['activo'] ?? true),
            ]);
        }

        $environmentIds=[];
        foreach (($payload['ambientes'] ?? []) as $item) {
            $environment=ProyectoAmbiente::create([
                'proyecto_id'=>$proyecto->id,
                'tipo'=>$item['tipo'],
                'nombre'=>$item['nombre'],
                'frontend_url'=>$item['frontend_url'] ?? null,
                'backend_url'=>$item['backend_url'] ?? null,
                'proveedor_frontend'=>$item['proveedor_frontend'] ?? null,
                'proveedor_backend'=>$item['proveedor_backend'] ?? null,
                'base_datos_referencia'=>$item['base_datos_referencia'] ?? null,
                'estado'=>$item['estado'] ?? 'pendiente',
                'notas'=>$item['notas'] ?? null,
            ]);
            $environmentIds[$environment->tipo]=$environment->id;
        }

        foreach (($payload['dominios'] ?? []) as $item) {
            ProyectoDominio::create([
                'proyecto_id'=>$proyecto->id,
                'ambiente_id'=>isset($item['ambiente_tipo']) ? ($environmentIds[$item['ambiente_tipo']] ?? null) : null,
                'dominio'=>strtolower(trim($item['dominio'])),
                'tipo'=>$item['tipo'] ?? 'web',
                'estado'=>$item['estado'] ?? 'pendiente',
                'verificado_at'=>$item['verificado_at'] ?? null,
                'notas'=>$item['notas'] ?? null,
            ]);
        }
    }

    private function assertStructuralLinks(Proyecto $proyecto,array $data):void
    {
        $fields=[
            'empresa_id'=>(int)$proyecto->empresa_id,
            'cliente_id'=>(int)$proyecto->cliente_id,
            'solicitud_id'=>$proyecto->solicitud_id===null?null:(int)$proyecto->solicitud_id,
        ];

        foreach($fields as $field=>$current){
            if(!array_key_exists($field,$data))continue;
            $incoming=$data[$field]===null?null:(int)$data[$field];
            if($incoming!==$current){
                throw ValidationException::withMessages([
                    $field=>'Este vínculo es estructural y no puede cambiarse después de crear el proyecto.',
                ]);
            }
        }
    }

    private function assertCompanyClient(int $empresaId,int $clienteId):void
    {
        $empresa=Empresa::query()->findOrFail($empresaId);
        if($empresa->cliente_id!==null && (int)$empresa->cliente_id!==$clienteId){
            throw ValidationException::withMessages([
                'cliente_id'=>'El cliente seleccionado no corresponde a la empresa del proyecto.',
            ]);
        }
    }

    private function phases():array{return app(WorkflowStateService::class)->proyectoPhases();}

    private function withWorkflow(Proyecto $proyecto): Proyecto
    {
        $proyecto->setAttribute('workflow',app(WorkflowStateService::class)->proyectoSnapshot($proyecto->fase,$proyecto->estado));
        return $proyecto;
    }
}
