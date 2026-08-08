<?php

namespace App\Http\Controllers;

use App\Models\Proyecto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientPeluqueriaController extends Controller
{
    private function project(Request $request): Proyecto
    {
        $clienteId = (int) $request->user()->cliente_id;
        $project = Proyecto::query()
            ->where('cliente_id', $clienteId)
            ->whereHas('aplicacion', fn ($q) => $q->where('nombre','like','%Peluquer%'))
            ->with(['empresa:id,nombre_comercial,actividad,ciudad,direccion','aplicacion'])
            ->latest()
            ->first();

        abort_unless($project && $project->empresa_id && $project->aplicacion, 404, 'No tienes una aplicación de peluquería asignada.');
        abort_unless((bool) $project->aplicacion->acceso_cliente, 403, 'Tu aplicación todavía no fue entregada. Contacta con Atención VITI.');
        abort_unless($project->aplicacion->estado === 'activo', 403, 'El acceso a esta aplicación está suspendido.');
        return $project;
    }

    private function forward(Request $request, string $method, ?int $id = null): JsonResponse
    {
        $project = $this->project($request);
        $request->merge(['empresa_id' => (int) $project->empresa_id]);
        $controller = app(PeluqueriaController::class);
        return $id === null ? $controller->{$method}($request) : $controller->{$method}($request, $id);
    }

    public function estado(Request $request): JsonResponse
    {
        $project = $this->project($request);
        return response()->json(['data'=>[
            'empresa'=>$project->empresa,
            'aplicacion'=>$project->aplicacion,
            'proyecto'=>[
                'codigo'=>$project->codigo,
                'nombre'=>$project->nombre,
                'estado'=>$project->estado,
                'fase'=>$project->fase,
                'progreso'=>$project->progreso,
            ],
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
