<?php

namespace App\Http\Controllers;

use App\Models\Aplicacion;
use App\Services\{SubscriptionAccessService,TenantContext};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientPeluqueriaExtrasController extends Controller
{
    private function appFor(Request $request): Aplicacion
    {
        $empresa = app(TenantContext::class)->resolve($request);
        $app = Aplicacion::query()
            ->where('empresa_id',$empresa->id)
            ->whereHas('catalogo',fn($q)=>$q->where('clave','peluqueria'))
            ->with('suscripcion')->latest()->first();
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
