<?php

namespace App\Providers;

use App\Http\Controllers\{ClientCatalogRequestController,ClientSaasController,NotificationCenterController,SaasController,SystemBackupController,SystemHealthController};
use App\Http\Middleware\AuditBusinessAccessChange;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SaasServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api','auth:sanctum','throttle:api'])
            ->prefix('api/v1/notificaciones')
            ->group(function (): void {
                Route::get('centro',[NotificationCenterController::class,'index']);
                Route::post('centro/leer-todo',[NotificationCenterController::class,'markAllRead']);
            });

        Route::middleware(['api','auth:sanctum','throttle:api','cliente'])
            ->prefix('api/v1/mi')
            ->group(function (): void {
                Route::get('negocios',[ClientSaasController::class,'negocios']);
                Route::get('negocio',[ClientSaasController::class,'negocio']);
                Route::put('negocio',[ClientSaasController::class,'actualizarNegocio']);
                Route::post('negocio/logo',[ClientSaasController::class,'logo']);
                Route::get('negocio/equipo',[ClientSaasController::class,'equipo']);
                Route::post('negocio/equipo',[ClientSaasController::class,'agregarUsuario']);
                Route::put('negocio/equipo/{usuario}',[ClientSaasController::class,'actualizarUsuario'])->middleware(AuditBusinessAccessChange::class);
                Route::delete('negocio/equipo/{usuario}',[ClientSaasController::class,'quitarUsuario'])->middleware(AuditBusinessAccessChange::class);
                Route::get('catalogo',[ClientSaasController::class,'catalogo']);
                Route::post('catalogo/{catalogoAplicacion}/solicitar',[ClientCatalogRequestController::class,'store']);
            });

        Route::middleware(['api','auth:sanctum','throttle:api','superadmin'])
            ->prefix('api/v1/saas')
            ->group(function (): void {
                Route::get('resumen',[SaasController::class,'overview']);
                Route::get('catalogo',[SaasController::class,'catalogo']);
                Route::post('catalogo/{catalogoAplicacion}/provisionar',[SaasController::class,'provisionar']);
                Route::get('planes',[SaasController::class,'planes']);
                Route::post('planes',[SaasController::class,'guardarPlan']);
                Route::put('planes/{plan}',[SaasController::class,'guardarPlan']);
                Route::put('negocios/{empresa}/plan',[SaasController::class,'asignarPlan']);
            });

        Route::middleware(['api','auth:sanctum','throttle:api','superadmin'])
            ->prefix('api/v1/system')
            ->group(function (): void {
                Route::get('health', SystemHealthController::class);
                Route::get('backups', [SystemBackupController::class,'index']);
                Route::post('backups', [SystemBackupController::class,'store'])->middleware('throttle:6,1');
                Route::post('backups/{backup}/verify', [SystemBackupController::class,'verify'])->middleware('throttle:12,1');
            });
    }
}
