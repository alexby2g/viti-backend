<?php

use App\Http\Controllers\{ServicioTecnicoController,ServicioTecnicoV1Controller};
use Illuminate\Support\Facades\Route;

$serviceTechnicalRoutes = function (): void {
    Route::get('estado',[ServicioTecnicoV1Controller::class,'estado'])->defaults('st_module','inicio');
    Route::get('resumen',[ServicioTecnicoController::class,'resumen'])->defaults('st_module','inicio');
    Route::get('referencias',[ServicioTecnicoV1Controller::class,'referencias'])->defaults('st_module','ordenes');

    Route::get('clientes',[ServicioTecnicoController::class,'clientes'])->defaults('st_module','clientes');
    Route::post('clientes',[ServicioTecnicoController::class,'guardarCliente'])->defaults('st_module','clientes')->defaults('st_manage',true);
    Route::put('clientes/{id}',[ServicioTecnicoController::class,'actualizarCliente'])->defaults('st_module','clientes')->defaults('st_manage',true);
    Route::delete('clientes/{id}',[ServicioTecnicoController::class,'eliminarCliente'])->defaults('st_module','clientes')->defaults('st_manage',true);

    Route::get('equipos',[ServicioTecnicoController::class,'equipos'])->defaults('st_module','equipos');
    Route::post('equipos',[ServicioTecnicoController::class,'guardarEquipo'])->defaults('st_module','equipos')->defaults('st_manage',true);
    Route::put('equipos/{id}',[ServicioTecnicoController::class,'actualizarEquipo'])->defaults('st_module','equipos')->defaults('st_manage',true);
    Route::delete('equipos/{id}',[ServicioTecnicoController::class,'eliminarEquipo'])->defaults('st_module','equipos')->defaults('st_manage',true);

    Route::get('tecnicos',[ServicioTecnicoController::class,'tecnicos'])->defaults('st_module','tecnicos');
    Route::get('usuarios-negocio',[ServicioTecnicoController::class,'usuariosNegocio'])->defaults('st_module','tecnicos')->defaults('st_manage',true);
    Route::post('tecnicos',[ServicioTecnicoController::class,'guardarTecnico'])->defaults('st_module','tecnicos')->defaults('st_manage',true);
    Route::put('tecnicos/{id}',[ServicioTecnicoController::class,'actualizarTecnico'])->defaults('st_module','tecnicos')->defaults('st_manage',true);
    Route::delete('tecnicos/{id}',[ServicioTecnicoController::class,'eliminarTecnico'])->defaults('st_module','tecnicos')->defaults('st_manage',true);

    Route::get('ordenes',[ServicioTecnicoController::class,'ordenes'])->defaults('st_module','ordenes');
    Route::post('ordenes',[ServicioTecnicoController::class,'guardarOrden'])->defaults('st_module','ordenes');
    Route::put('ordenes/{id}',[ServicioTecnicoController::class,'actualizarOrden'])->defaults('st_module','ordenes');
    Route::delete('ordenes/{id}',[ServicioTecnicoV1Controller::class,'eliminarOrden'])->defaults('st_module','ordenes')->defaults('st_manage',true);
    Route::post('ordenes/{id}/decision',[ServicioTecnicoController::class,'decision'])->defaults('st_module','ordenes');
    Route::post('ordenes/{id}/estado',[ServicioTecnicoV1Controller::class,'cambiarEstado'])->defaults('st_module','ordenes');
    Route::post('ordenes/{id}/finalizar-trabajo',[ServicioTecnicoController::class,'finalizarTrabajo'])->defaults('st_module','ordenes');
    Route::post('ordenes/{id}/pagos',[ServicioTecnicoController::class,'registrarPago'])->defaults('st_module','pagos');
    Route::post('ordenes/{id}/evidencias',[ServicioTecnicoController::class,'subirEvidencia'])->defaults('st_module','ordenes');
    Route::get('ordenes/{id}/pdf',[ServicioTecnicoV1Controller::class,'reporteOrden'])->defaults('st_module','historial')->defaults('st_manage',true);

    Route::get('pagos',[ServicioTecnicoController::class,'pagos'])->defaults('st_module','pagos');
    Route::get('garantias',[ServicioTecnicoController::class,'garantias'])->defaults('st_module','garantias');
    Route::get('historial',[ServicioTecnicoController::class,'historial'])->defaults('st_module','historial');
    Route::get('reportes/resumen',[ServicioTecnicoV1Controller::class,'reporteResumen'])->defaults('st_module','historial')->defaults('st_manage',true);

    Route::get('evidencias/{evidencia}/descargar',[ServicioTecnicoController::class,'descargarEvidencia'])->defaults('st_module','ordenes');
    Route::delete('evidencias/{evidencia}',[ServicioTecnicoV1Controller::class,'eliminarEvidencia'])->defaults('st_module','ordenes')->defaults('st_manage',true);
};

Route::middleware(['auth:sanctum','throttle:api','platform_admin'])
    ->prefix('apps/servicio-tecnico')
    ->group($serviceTechnicalRoutes);

Route::middleware(['auth:sanctum','throttle:api','cliente','servicio_tecnico_client'])
    ->prefix('mi/apps/servicio-tecnico')
    ->group($serviceTechnicalRoutes);
