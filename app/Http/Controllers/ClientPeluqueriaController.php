<?php

namespace App\Http\Controllers;

use App\Models\Aplicacion;
use App\Services\{SubscriptionAccessService,TenantContext};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientPeluqueriaController extends Controller
{
    private function appFor(Request $request): Aplicacion
    {
        $empresa = app(TenantContext::class)->resolve($request);
        $app = Aplicacion::query()
            ->where('empresa_id',$empresa->id)
            ->whereHas('catalogo',fn($q)=>$q->where('clave','peluqueria'))
            ->with(['empresa:id,nombre_comercial,actividad,ciudad,direccion','proyecto','catalogo','suscripcion'])
            ->latest()->first();

        abort_unless($app,404,'Este negocio no tiene una aplicación de peluquería asignada.');
        abort_unless((bool)$app->acceso_cliente,403,'Tu aplicación todavía no fue entregada. Contacta con Atención VITI.');
        abort_unless($app->estado === 'activo',403,'El acceso a esta aplicación está suspendido.');
        app(SubscriptionAccessService::class)->assertCanUse($app);
        return $app;
    }

    private function forward(Request $request, string $method, ?int $id = null): JsonResponse
    {
        $app = $this->appFor($request);
        $request->merge(['empresa_id'=>(int)$app->empresa_id]);
        $controller = app(PeluqueriaController::class);
        return $id === null ? $controller->{$method}($request) : $controller->{$method}($request,$id);
    }

    public function estado(Request $request): JsonResponse
    {
        $app = $this->appFor($request);
        return response()->json(['data'=>[
            'empresa'=>$app->empresa,
            'aplicacion'=>$app,
            'proyecto'=>$app->proyecto ? [
                'codigo'=>$app->proyecto->codigo,'nombre'=>$app->proyecto->nombre,'estado'=>$app->proyecto->estado,
                'fase'=>$app->proyecto->fase,'progreso'=>$app->proyecto->progreso,
            ] : null,
        ]]);
    }

    public function resumen(Request $r):JsonResponse{return $this->forward($r,'resumen');}
    public function clientes(Request $r):JsonResponse{return $this->forward($r,'clientes');}
    public function guardarCliente(Request $r):JsonResponse{return $this->forward($r,'guardarCliente');}
    public function actualizarCliente(Request $r,int $id):JsonResponse{return $this->forward($r,'actualizarCliente',$id);}
    public function eliminarCliente(Request $r,int $id):JsonResponse{return $this->forward($r,'eliminarCliente',$id);}
    public function servicios(Request $r):JsonResponse{return $this->forward($r,'servicios');}
    public function guardarServicio(Request $r):JsonResponse{return $this->forward($r,'guardarServicio');}
    public function actualizarServicio(Request $r,int $id):JsonResponse{return $this->forward($r,'actualizarServicio',$id);}
    public function eliminarServicio(Request $r,int $id):JsonResponse{return $this->forward($r,'eliminarServicio',$id);}
    public function personal(Request $r):JsonResponse{return $this->forward($r,'personal');}
    public function guardarPersonal(Request $r):JsonResponse{return $this->forward($r,'guardarPersonal');}
    public function actualizarPersonal(Request $r,int $id):JsonResponse{return $this->forward($r,'actualizarPersonal',$id);}
    public function eliminarPersonal(Request $r,int $id):JsonResponse{return $this->forward($r,'eliminarPersonal',$id);}
    public function citas(Request $r):JsonResponse{return $this->forward($r,'citas');}
    public function guardarCita(Request $r):JsonResponse{return $this->forward($r,'guardarCita');}
    public function actualizarCita(Request $r,int $id):JsonResponse{return $this->forward($r,'actualizarCita',$id);}
    public function eliminarCita(Request $r,int $id):JsonResponse{return $this->forward($r,'eliminarCita',$id);}
    public function atenciones(Request $r):JsonResponse{return $this->forward($r,'atenciones');}
    public function iniciarAtencion(Request $r):JsonResponse{return $this->forward($r,'iniciarAtencion');}
    public function finalizarAtencion(Request $r,int $id):JsonResponse{return $this->forward($r,'finalizarAtencion',$id);}
    public function registrarPago(Request $r,int $id):JsonResponse{return $this->forward($r,'registrarPago',$id);}
    public function historial(Request $r):JsonResponse{return $this->forward($r,'historial');}
}
