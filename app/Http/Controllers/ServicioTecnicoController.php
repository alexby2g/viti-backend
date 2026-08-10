<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;
use App\Support\Audit;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServicioTecnicoController extends Controller
{
    private const STATES = ['recibido','diagnostico','esperando_aprobacion','reparacion','pruebas','listo_entrega','entregado','sin_reparacion'];

    public function resumen(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId = $this->empresaId($request,$tenants,'inicio');
        $today = now()->toDateString();
        $total = (float) DB::table('servicio_tecnico_ordenes')->where('empresa_id',$empresaId)->sum('total');
        $paid = (float) DB::table('servicio_tecnico_pagos')->where('empresa_id',$empresaId)->where('estado','pagado')->sum('monto');
        $agenda = $this->orderQuery($empresaId)
            ->whereDate('o.fecha_programada',$today)
            ->whereNotIn('o.estado',['entregado','sin_reparacion'])
            ->orderBy('o.hora_programada')->limit(30)->get();

        return response()->json(['data'=>[
            'clientes'=>DB::table('servicio_tecnico_clientes')->where('empresa_id',$empresaId)->where('activo',true)->count(),
            'equipos'=>DB::table('servicio_tecnico_equipos')->where('empresa_id',$empresaId)->where('activo',true)->count(),
            'tecnicos'=>DB::table('servicio_tecnico_tecnicos')->where('empresa_id',$empresaId)->where('activo',true)->count(),
            'ordenes_abiertas'=>DB::table('servicio_tecnico_ordenes')->where('empresa_id',$empresaId)->whereNotIn('estado',['entregado','sin_reparacion'])->count(),
            'esperando_aprobacion'=>DB::table('servicio_tecnico_ordenes')->where('empresa_id',$empresaId)->where('estado','esperando_aprobacion')->count(),
            'listos_entrega'=>DB::table('servicio_tecnico_ordenes')->where('empresa_id',$empresaId)->where('estado','listo_entrega')->count(),
            'por_cobrar'=>max(0,$total-$paid),
            'agenda_hoy'=>$this->hydrateOrders($agenda,$empresaId),
        ]]);
    }

    public function clientes(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'clientes');
        $q=DB::table('servicio_tecnico_clientes')->where('empresa_id',$empresaId);
        if($request->filled('buscar')){
            $term='%'.trim((string)$request->input('buscar')).'%';
            $q->where(fn($x)=>$x->where('nombre','like',$term)->orWhere('telefono','like',$term)->orWhere('whatsapp','like',$term));
        }
        return response()->json(['data'=>$q->latest('id')->limit(300)->get()]);
    }

    public function guardarCliente(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'clientes');
        $data=$this->datosCliente($request,$empresaId);
        $id=DB::table('servicio_tecnico_clientes')->insertGetId($data+['empresa_id'=>$empresaId,'created_at'=>now(),'updated_at'=>now()]);
        Audit::log($request,'servicio_tecnico_cliente_creado',null,'Se registró un cliente en Servicio Técnico VITI.');
        return response()->json(['data'=>DB::table('servicio_tecnico_clientes')->find($id)],201);
    }

    public function actualizarCliente(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'clientes');
        $this->scoped('servicio_tecnico_clientes',$empresaId,$id);
        $data=$this->datosCliente($request,$empresaId,$id);
        DB::table('servicio_tecnico_clientes')->where('id',$id)->update($data+['updated_at'=>now()]);
        return response()->json(['data'=>DB::table('servicio_tecnico_clientes')->find($id)]);
    }

    public function eliminarCliente(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'clientes');
        $this->scoped('servicio_tecnico_clientes',$empresaId,$id);
        abort_if(DB::table('servicio_tecnico_ordenes')->where('empresa_id',$empresaId)->where('cliente_id',$id)->exists(),422,'Este cliente tiene historial. Puedes marcarlo como inactivo, pero no eliminarlo.');
        DB::table('servicio_tecnico_equipos')->where('empresa_id',$empresaId)->where('cliente_id',$id)->delete();
        DB::table('servicio_tecnico_clientes')->where('id',$id)->delete();
        return response()->json(status:204);
    }

    public function equipos(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'equipos');
        $q=DB::table('servicio_tecnico_equipos as e')->join('servicio_tecnico_clientes as c','c.id','=','e.cliente_id')
            ->where('e.empresa_id',$empresaId)->select('e.*','c.nombre as cliente_nombre');
        if($request->filled('cliente_id'))$q->where('e.cliente_id',$request->integer('cliente_id'));
        return response()->json(['data'=>$q->latest('e.id')->limit(300)->get()]);
    }

    public function guardarEquipo(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'equipos');
        $data=$this->datosEquipo($request,$empresaId);
        $id=DB::table('servicio_tecnico_equipos')->insertGetId($data+['empresa_id'=>$empresaId,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('servicio_tecnico_equipos')->find($id)],201);
    }

    public function actualizarEquipo(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'equipos');
        $this->scoped('servicio_tecnico_equipos',$empresaId,$id);
        $data=$this->datosEquipo($request,$empresaId);
        DB::table('servicio_tecnico_equipos')->where('id',$id)->update($data+['updated_at'=>now()]);
        return response()->json(['data'=>DB::table('servicio_tecnico_equipos')->find($id)]);
    }

    public function eliminarEquipo(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'equipos');
        $this->scoped('servicio_tecnico_equipos',$empresaId,$id);
        abort_if(DB::table('servicio_tecnico_ordenes')->where('empresa_id',$empresaId)->where('equipo_id',$id)->exists(),422,'La computadora tiene historial y no puede eliminarse. Puedes marcarla como inactiva.');
        DB::table('servicio_tecnico_equipos')->where('id',$id)->delete();
        return response()->json(status:204);
    }

    public function tecnicos(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'tecnicos');
        return response()->json(['data'=>DB::table('servicio_tecnico_tecnicos as t')->leftJoin('usuarios as u','u.id','=','t.usuario_id')
            ->where('t.empresa_id',$empresaId)->select('t.*','u.usuario','u.estado as usuario_estado')->latest('t.id')->limit(150)->get()]);
    }

    public function usuariosNegocio(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'tecnicos');
        $items=DB::table('empresa_usuario as eu')->join('usuarios as u','u.id','=','eu.usuario_id')
            ->where('eu.empresa_id',$empresaId)->where('eu.activo',true)->where('u.estado','activo')
            ->select('u.id','u.nombre','u.apellido','u.usuario','u.telefono')->orderBy('u.nombre')->get();
        return response()->json(['data'=>$items]);
    }

    public function guardarTecnico(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'tecnicos');
        $data=$this->datosTecnico($request,$empresaId);
        $id=DB::table('servicio_tecnico_tecnicos')->insertGetId($data+['empresa_id'=>$empresaId,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('servicio_tecnico_tecnicos')->find($id)],201);
    }

    public function actualizarTecnico(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'tecnicos');
        $this->scoped('servicio_tecnico_tecnicos',$empresaId,$id);
        $data=$this->datosTecnico($request,$empresaId,$id);
        DB::table('servicio_tecnico_tecnicos')->where('id',$id)->update($data+['updated_at'=>now()]);
        return response()->json(['data'=>DB::table('servicio_tecnico_tecnicos')->find($id)]);
    }

    public function eliminarTecnico(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'tecnicos');
        $this->scoped('servicio_tecnico_tecnicos',$empresaId,$id);
        abort_if(DB::table('servicio_tecnico_ordenes')->where('empresa_id',$empresaId)->where('tecnico_id',$id)->exists(),422,'El técnico tiene historial. Puedes marcarlo como inactivo.');
        DB::table('servicio_tecnico_tecnicos')->where('id',$id)->delete();
        return response()->json(status:204);
    }

    public function ordenes(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'ordenes');
        $q=$this->orderQuery($empresaId);
        if($request->filled('estado'))$q->where('o.estado',$request->string('estado'));
        if($request->filled('cliente_id'))$q->where('o.cliente_id',$request->integer('cliente_id'));
        return response()->json(['data'=>$this->hydrateOrders($q->latest('o.id')->limit(500)->get(),$empresaId)]);
    }

    public function guardarOrden(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'ordenes');
        $data=$this->datosOrden($request,$empresaId);
        $id=DB::transaction(function()use($data,$empresaId):int{
            $id=DB::table('servicio_tecnico_ordenes')->insertGetId($data+[
                'empresa_id'=>$empresaId,'codigo'=>'TMP-'.bin2hex(random_bytes(5)),'estado'=>'recibido','decision_cliente'=>'pendiente',
                'total'=>max(0,(float)($data['costo_servicio']??0)-(float)($data['descuento']??0)),'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('servicio_tecnico_ordenes')->where('id',$id)->update(['codigo'=>'ST-'.now()->format('Ym').'-'.str_pad((string)$id,5,'0',STR_PAD_LEFT)]);
            return $id;
        });
        Audit::log($request,'servicio_tecnico_orden_creada',null,'Se creó una orden de servicio técnico.');
        return response()->json(['data'=>$this->findOrder($empresaId,$id)],201);
    }

    public function actualizarOrden(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'ordenes');
        $order=$this->scoped('servicio_tecnico_ordenes',$empresaId,$id);
        abort_if(in_array($order->estado,['entregado','sin_reparacion'],true),422,'La orden está cerrada y debe conservarse en el historial.');
        $data=$this->datosOrden($request,$empresaId,$id);
        if(!empty($data['diagnostico']) && empty($data['propuesta']))$data['estado']='diagnostico';
        if(!empty($data['propuesta']) && $order->decision_cliente==='pendiente')$data['estado']='esperando_aprobacion';
        if($order->decision_cliente==='aceptado' && !in_array($order->estado,['pruebas','listo_entrega'],true))$data['estado']='reparacion';
        $data['total']=max(0,(float)($data['costo_servicio']??$order->costo_servicio)-(float)($data['descuento']??$order->descuento));
        $paid=(float)DB::table('servicio_tecnico_pagos')->where('empresa_id',$empresaId)->where('orden_id',$id)->where('estado','pagado')->sum('monto');
        abort_if($data['total']+0.001<$paid,422,'El total no puede quedar por debajo de lo ya pagado.');
        DB::table('servicio_tecnico_ordenes')->where('id',$id)->update($data+['updated_at'=>now()]);
        return response()->json(['data'=>$this->findOrder($empresaId,$id)]);
    }

    public function eliminarOrden(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'ordenes');
        $this->scoped('servicio_tecnico_ordenes',$empresaId,$id);
        abort_if(DB::table('servicio_tecnico_pagos')->where('orden_id',$id)->exists()||DB::table('servicio_tecnico_evidencias')->where('orden_id',$id)->exists(),422,'La orden tiene pagos o evidencias y debe conservarse.');
        DB::table('servicio_tecnico_ordenes')->where('id',$id)->delete();
        return response()->json(status:204);
    }

    public function decision(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'ordenes');
        $order=$this->scoped('servicio_tecnico_ordenes',$empresaId,$id);
        abort_if(in_array($order->estado,['entregado','sin_reparacion'],true),422,'La orden ya está cerrada.');
        $data=$request->validate(['decision'=>['required',Rule::in(['aceptado','rechazado'])],'motivo_rechazo'=>['nullable','string','max:3000','required_if:decision,rechazado']]);
        abort_if($data['decision']==='aceptado'&&(empty($order->diagnostico)||empty($order->propuesta)),422,'Registra diagnóstico y propuesta antes de autorizar la reparación.');
        DB::table('servicio_tecnico_ordenes')->where('id',$id)->update([
            'decision_cliente'=>$data['decision'],'motivo_rechazo'=>$data['motivo_rechazo']??null,'decision_at'=>now(),
            'estado'=>$data['decision']==='aceptado'?'reparacion':'sin_reparacion','finalizada_at'=>$data['decision']==='rechazado'?now():null,'updated_at'=>now(),
        ]);
        return response()->json(['data'=>$this->findOrder($empresaId,$id)]);
    }

    public function cambiarEstado(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'ordenes');
        $order=$this->scoped('servicio_tecnico_ordenes',$empresaId,$id);
        $data=$request->validate(['estado'=>['required',Rule::in(self::STATES)]]);
        $target=$data['estado'];
        abort_if(in_array($order->estado,['entregado','sin_reparacion'],true),422,'La orden ya está cerrada.');
        if(in_array($target,['reparacion','pruebas','listo_entrega','entregado'],true))abort_unless($order->decision_cliente==='aceptado',422,'El cliente debe aceptar la propuesta antes de continuar.');
        if($target==='pruebas')abort_if(empty($order->trabajo_realizado),422,'Registra el trabajo realizado antes de pasar a pruebas.');
        if($target==='entregado')abort_unless($order->estado==='listo_entrega',422,'Marca primero el equipo como listo para entregar.');
        DB::table('servicio_tecnico_ordenes')->where('id',$id)->update(['estado'=>$target,'finalizada_at'=>$target==='entregado'?now():null,'updated_at'=>now()]);
        return response()->json(['data'=>$this->findOrder($empresaId,$id)]);
    }

    public function finalizarTrabajo(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'ordenes');
        $order=$this->scoped('servicio_tecnico_ordenes',$empresaId,$id);
        abort_unless($order->decision_cliente==='aceptado',422,'El cliente debe aceptar la propuesta antes de registrar la reparación.');
        $data=$request->validate([
            'trabajo_realizado'=>['required','string','max:8000'],'recomendaciones'=>['nullable','string','max:5000'],
            'garantia_dias'=>['nullable','integer','min:0','max:3650'],'condiciones_garantia'=>['nullable','string','max:5000'],
        ]);
        $days=(int)($data['garantia_dias']??0);
        DB::table('servicio_tecnico_ordenes')->where('id',$id)->update([
            'trabajo_realizado'=>$data['trabajo_realizado'],'recomendaciones'=>$data['recomendaciones']??null,
            'garantia_dias'=>$days,'garantia_inicio'=>$days?now()->toDateString():null,'garantia_fin'=>$days?now()->addDays($days)->toDateString():null,
            'condiciones_garantia'=>$data['condiciones_garantia']??null,'estado'=>'pruebas','updated_at'=>now(),
        ]);
        return response()->json(['data'=>$this->findOrder($empresaId,$id)]);
    }

    public function pagos(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'pagos');
        $items=DB::table('servicio_tecnico_pagos as p')->join('servicio_tecnico_ordenes as o','o.id','=','p.orden_id')
            ->join('servicio_tecnico_clientes as c','c.id','=','o.cliente_id')->where('p.empresa_id',$empresaId)
            ->select('p.*','o.codigo as orden_codigo','c.nombre as cliente_nombre')->latest('p.pagado_at')->limit(500)->get();
        return response()->json(['data'=>$items]);
    }

    public function registrarPago(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'pagos');
        $order=$this->scoped('servicio_tecnico_ordenes',$empresaId,$id);
        $data=$request->validate(['monto'=>['required','numeric','gt:0'],'metodo'=>['required',Rule::in(['efectivo','qr','transferencia','tarjeta','otro'])],'referencia'=>['nullable','string','max:160']]);
        $paid=(float)DB::table('servicio_tecnico_pagos')->where('empresa_id',$empresaId)->where('orden_id',$id)->where('estado','pagado')->sum('monto');
        abort_if($paid+(float)$data['monto']>(float)$order->total+0.001,422,'El pago supera el saldo pendiente.');
        $paymentId=DB::table('servicio_tecnico_pagos')->insertGetId($data+['empresa_id'=>$empresaId,'orden_id'=>$id,'estado'=>'pagado','pagado_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('servicio_tecnico_pagos')->find($paymentId)],201);
    }

    public function garantias(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'garantias');
        $items=$this->orderQuery($empresaId)->whereNotNull('o.garantia_fin')->orderByDesc('o.garantia_fin')->limit(500)->get();
        return response()->json(['data'=>$this->hydrateOrders($items,$empresaId)]);
    }

    public function historial(Request $request,TenantContext $tenants):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'historial');
        $items=$this->orderQuery($empresaId)->whereIn('o.estado',['entregado','sin_reparacion'])->latest('o.finalizada_at')->limit(500)->get();
        return response()->json(['data'=>$this->hydrateOrders($items,$empresaId)]);
    }

    public function subirEvidencia(Request $request,TenantContext $tenants,int $id):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'ordenes');
        $this->scoped('servicio_tecnico_ordenes',$empresaId,$id);
        $data=$request->validate([
            'archivo'=>['required','image','mimes:jpg,jpeg,png,webp','max:8192'],
            'etapa'=>['required',Rule::in(['recepcion','diagnostico','reparacion','pruebas','entrega'])],
            'descripcion'=>['nullable','string','max:1000'],
        ]);
        $file=$request->file('archivo');
        $path=$file->store('servicio-tecnico/'.now()->format('Y/m'),'private_uploads');
        try{
            $evidenceId=DB::table('servicio_tecnico_evidencias')->insertGetId([
                'empresa_id'=>$empresaId,'orden_id'=>$id,'subido_por'=>$request->user()->id,'etapa'=>$data['etapa'],
                'nombre_original'=>$file->getClientOriginalName(),'ruta'=>$path,'mime'=>$file->getMimeType(),'tamano'=>$file->getSize(),
                'descripcion'=>$data['descripcion']??null,'created_at'=>now(),'updated_at'=>now(),
            ]);
        }catch(\Throwable $e){Storage::disk('private_uploads')->delete($path);throw $e;}
        Audit::log($request,'servicio_tecnico_evidencia_subida',null,'Se agregó evidencia privada a una orden de servicio técnico.');
        return response()->json(['data'=>DB::table('servicio_tecnico_evidencias')->find($evidenceId)],201);
    }

    public function descargarEvidencia(Request $request,TenantContext $tenants,int $evidencia):StreamedResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'ordenes');
        $item=DB::table('servicio_tecnico_evidencias')->where('empresa_id',$empresaId)->where('id',$evidencia)->first();
        abort_unless($item,404,'La evidencia no existe.');
        $disk=Storage::disk('private_uploads');abort_unless($disk->exists($item->ruta),404,'El archivo ya no está disponible.');
        $stream=$disk->readStream($item->ruta);abort_unless(is_resource($stream),404,'No pudimos abrir la evidencia.');
        return response()->streamDownload(function()use($stream):void{fpassthru($stream);fclose($stream);},$item->nombre_original,['Content-Type'=>$item->mime?:'application/octet-stream','Cache-Control'=>'private, no-store']);
    }

    public function eliminarEvidencia(Request $request,TenantContext $tenants,int $evidencia):JsonResponse
    {
        $empresaId=$this->empresaId($request,$tenants,'ordenes');
        $item=DB::table('servicio_tecnico_evidencias')->where('empresa_id',$empresaId)->where('id',$evidencia)->first();
        abort_unless($item,404,'La evidencia no existe.');
        Storage::disk('private_uploads')->delete($item->ruta);
        DB::table('servicio_tecnico_evidencias')->where('id',$item->id)->delete();
        return response()->json(status:204);
    }

    private function empresaId(Request $request,TenantContext $tenants,string $module):int
    {
        $empresa=$tenants->resolve($request);
        $hasApp=DB::table('aplicaciones as a')->join('catalogo_aplicaciones as c','c.id','=','a.catalogo_aplicacion_id')
            ->where('a.empresa_id',$empresa->id)->where('c.clave','servicio-tecnico')->whereNotIn('a.estado',['retirado'])->whereNull('a.deleted_at')->exists();
        abort_unless($hasApp,404,'Este negocio no tiene Servicio Técnico VITI habilitado.');
        $tenants->assertModule($empresa,$module);
        return (int)$empresa->id;
    }

    private function datosCliente(Request $request,int $empresaId,?int $ignore=null):array
    {
        return $request->validate([
            'nombre'=>['required','string','max:180'],
            'telefono'=>['nullable','string','max:30',Rule::unique('servicio_tecnico_clientes','telefono')->where(fn($q)=>$q->where('empresa_id',$empresaId))->ignore($ignore)],
            'whatsapp'=>['nullable','string','max:30'],'direccion'=>['nullable','string','max:255'],'observaciones'=>['nullable','string','max:5000'],'activo'=>['sometimes','boolean'],
        ]);
    }

    private function datosEquipo(Request $request,int $empresaId):array
    {
        $data=$request->validate([
            'cliente_id'=>['required','integer'],'tipo'=>['required','string','max:120'],'marca'=>['nullable','string','max:120'],'modelo'=>['nullable','string','max:120'],
            'serie'=>['nullable','string','max:120'],'especificaciones'=>['nullable','string','max:5000'],'accesorios_recibidos'=>['nullable','string','max:5000'],
            'estado_recepcion'=>['nullable','string','max:5000'],'observaciones'=>['nullable','string','max:5000'],'activo'=>['sometimes','boolean'],
        ]);
        $this->scoped('servicio_tecnico_clientes',$empresaId,(int)$data['cliente_id']);return $data;
    }

    private function datosTecnico(Request $request,int $empresaId,?int $ignore=null):array
    {
        $data=$request->validate([
            'usuario_id'=>['nullable','integer',Rule::unique('servicio_tecnico_tecnicos','usuario_id')->where(fn($q)=>$q->where('empresa_id',$empresaId))->ignore($ignore)],
            'nombre'=>['required','string','max:180'],'telefono'=>['nullable','string','max:30'],'especialidad'=>['nullable','string','max:160'],'activo'=>['sometimes','boolean'],
        ]);
        if(!empty($data['usuario_id']))abort_unless(DB::table('empresa_usuario')->where('empresa_id',$empresaId)->where('usuario_id',$data['usuario_id'])->where('activo',true)->exists(),422,'La cuenta seleccionada no pertenece al equipo activo de este negocio.');
        return $data;
    }

    private function datosOrden(Request $request,int $empresaId,?int $id=null):array
    {
        $data=$request->validate([
            'cliente_id'=>['required','integer'],'equipo_id'=>['nullable','integer'],'tecnico_id'=>['nullable','integer'],
            'fecha_recepcion'=>['required','date'],'fecha_programada'=>['nullable','date'],'hora_programada'=>['nullable','date_format:H:i'],
            'prioridad'=>['required',Rule::in(['baja','normal','alta','urgente'])],'problema_reportado'=>['required','string','max:5000'],
            'diagnostico'=>['nullable','string','max:8000'],'propuesta'=>['nullable','string','max:8000'],'trabajo_realizado'=>['nullable','string','max:8000'],
            'recomendaciones'=>['nullable','string','max:5000'],'costo_servicio'=>['nullable','numeric','min:0'],'descuento'=>['nullable','numeric','min:0'],
        ]);
        $this->scoped('servicio_tecnico_clientes',$empresaId,(int)$data['cliente_id']);
        if(!empty($data['equipo_id'])){$equipment=$this->scoped('servicio_tecnico_equipos',$empresaId,(int)$data['equipo_id']);abort_unless((int)$equipment->cliente_id===(int)$data['cliente_id'],422,'La computadora no pertenece al cliente seleccionado.');}
        if(!empty($data['tecnico_id']))$this->scoped('servicio_tecnico_tecnicos',$empresaId,(int)$data['tecnico_id']);
        if($id)$this->scoped('servicio_tecnico_ordenes',$empresaId,$id);
        return $data;
    }

    private function scoped(string $table,int $empresaId,int $id):object
    {
        $item=DB::table($table)->where('empresa_id',$empresaId)->where('id',$id)->first();abort_unless($item,404,'El registro solicitado no existe en este Servicio Técnico.');return $item;
    }

    private function orderQuery(int $empresaId):Builder
    {
        return DB::table('servicio_tecnico_ordenes as o')->join('servicio_tecnico_clientes as c','c.id','=','o.cliente_id')
            ->leftJoin('servicio_tecnico_equipos as e','e.id','=','o.equipo_id')->leftJoin('servicio_tecnico_tecnicos as t','t.id','=','o.tecnico_id')
            ->where('o.empresa_id',$empresaId)->select('o.*','c.nombre as cliente_nombre','c.telefono as cliente_telefono','e.tipo as equipo_tipo','e.marca as equipo_marca','e.modelo as equipo_modelo','e.serie as equipo_serie','t.nombre as tecnico_nombre');
    }

    private function hydrateOrders($items,int $empresaId)
    {
        $ids=$items->pluck('id')->all();if(!$ids)return $items;
        $payments=DB::table('servicio_tecnico_pagos')->where('empresa_id',$empresaId)->whereIn('orden_id',$ids)->where('estado','pagado')->get()->groupBy('orden_id');
        $evidence=DB::table('servicio_tecnico_evidencias')->where('empresa_id',$empresaId)->whereIn('orden_id',$ids)->orderByDesc('id')->get()->groupBy('orden_id');
        return $items->map(function($order)use($payments,$evidence){$order->pagos=($payments[$order->id]??collect())->values();$order->pagado=(float)$order->pagos->sum('monto');$order->saldo=max(0,(float)$order->total-$order->pagado);$order->evidencias=($evidence[$order->id]??collect())->values();return $order;});
    }

    private function findOrder(int $empresaId,int $id):object
    {
        $items=$this->hydrateOrders($this->orderQuery($empresaId)->where('o.id',$id)->get(),$empresaId);abort_if($items->isEmpty(),404,'La orden no existe.');return $items->first();
    }
}