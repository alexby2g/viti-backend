<?php

namespace App\Http\Controllers;

use App\Models\Proyecto;
use App\Services\SubscriptionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientPeluqueriaExtrasController extends Controller
{
    private function project(Request $request): Proyecto
    {
        $clienteId = (int) $request->user()->cliente_id;
        $project = Proyecto::query()
            ->where('cliente_id',$clienteId)
            ->whereHas('aplicacion',fn($q)=>$q->where('nombre','like','%Peluquer%'))
            ->with('aplicacion.suscripcion')
            ->latest()
            ->first();

        abort_unless($project && $project->empresa_id && $project->aplicacion,404,'No tienes una aplicación de peluquería asignada.');
        abort_unless((bool)$project->aplicacion->acceso_cliente,403,'Tu aplicación todavía no fue entregada. Contacta con Atención VITI.');
        abort_unless($project->aplicacion->estado === 'activo',403,'El acceso a esta aplicación está suspendido.');
        app(SubscriptionAccessService::class)->assertCanUse($project->aplicacion);
        return $project;
    }

    private function forward(Request $request, string $method, ?int $id = null): JsonResponse
    {
        $project = $this->project($request);
        $request->merge(['empresa_id'=>(int)$project->empresa_id]);
        $controller = app(PeluqueriaExtrasController::class);
        return $id === null ? $controller->{$method}($request) : $controller->{$method}($request,$id);
    }

    public function combos(Request $r):JsonResponse{return $this->forward($r,'combos');}
    public function guardarCombo(Request $r):JsonResponse{return $this->forward($r,'guardarCombo');}
    public function actualizarCombo(Request $r,int $id):JsonResponse{return $this->forward($r,'actualizarCombo',$id);}
    public function eliminarCombo(Request $r,int $id):JsonResponse{return $this->forward($r,'eliminarCombo',$id);}
    public function productos(Request $r):JsonResponse{return $this->forward($r,'productos');}
    public function guardarProducto(Request $r):JsonResponse{return $this->forward($r,'guardarProducto');}
    public function actualizarProducto(Request $r,int $id):JsonResponse{return $this->forward($r,'actualizarProducto',$id);}
    public function eliminarProducto(Request $r,int $id):JsonResponse{return $this->forward($r,'eliminarProducto',$id);}
    public function movimientos(Request $r):JsonResponse{return $this->forward($r,'movimientos');}
    public function registrarMovimiento(Request $r):JsonResponse{return $this->forward($r,'registrarMovimiento');}
    public function reportes(Request $r):JsonResponse{return $this->forward($r,'reportes');}
}
