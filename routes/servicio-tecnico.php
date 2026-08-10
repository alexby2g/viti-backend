<?php

use App\Http\Controllers\ServicioTecnicoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum','throttle:api','platform_admin'])->prefix('apps/servicio-tecnico')->group(function (): void {
    Route::get('resumen',[ServicioTecnicoController::class,'resumen']);

    Route::get('clientes',[ServicioTecnicoController::class,'clientes']);
    Route::post('clientes',[ServicioTecnicoController::class,'guardarCliente']);
    Route::put('clientes/{id}',[ServicioTecnicoController::class,'actualizarCliente']);
    Route::delete('clientes/{id}',[ServicioTecnicoController::class,'eliminarCliente']);

    Route::get('equipos',[ServicioTecnicoController::class,'equipos']);
    Route::post('equipos',[ServicioTecnicoController::class,'guardarEquipo']);
    Route::put('equipos/{id}',[ServicioTecnicoController::class,'actualizarEquipo']);
    Route::delete('equipos/{id}',[ServicioTecnicoController::class,'eliminarEquipo']);

    Route::get('tecnicos',[ServicioTecnicoController::class,'tecnicos']);
    Route::get('usuarios-negocio',[ServicioTecnicoController::class,'usuariosNegocio']);
    Route::post('tecnicos',[ServicioTecnicoController::class,'guardarTecnico']);
    Route::put('tecnicos/{id}',[ServicioTecnicoController::class,'actualizarTecnico']);
    Route::delete('tecnicos/{id}',[ServicioTecnicoController::class,'eliminarTecnico']);

    Route::get('ordenes',[ServicioTecnicoController::class,'ordenes']);
    Route::post('ordenes',[ServicioTecnicoController::class,'guardarOrden']);
    Route::put('ordenes/{id}',[ServicioTecnicoController::class,'actualizarOrden']);
    Route::delete('ordenes/{id}',[ServicioTecnicoController::class,'eliminarOrden']);
    Route::post('ordenes/{id}/decision',[ServicioTecnicoController::class,'decision']);
    Route::post('ordenes/{id}/estado',[ServicioTecnicoController::class,'cambiarEstado']);
    Route::post('ordenes/{id}/finalizar-trabajo',[ServicioTecnicoController::class,'finalizarTrabajo']);
    Route::post('ordenes/{id}/pagos',[ServicioTecnicoController::class,'registrarPago']);
    Route::post('ordenes/{id}/evidencias',[ServicioTecnicoController::class,'subirEvidencia']);

    Route::get('pagos',[ServicioTecnicoController::class,'pagos']);
    Route::get('garantias',[ServicioTecnicoController::class,'garantias']);
    Route::get('historial',[ServicioTecnicoController::class,'historial']);
    Route::get('evidencias/{evidencia}/descargar',[ServicioTecnicoController::class,'descargarEvidencia']);
    Route::delete('evidencias/{evidencia}',[ServicioTecnicoController::class,'eliminarEvidencia']);
});