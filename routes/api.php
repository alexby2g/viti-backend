<?php

use App\Http\Controllers\{AplicacionController,ArchivoController,AuditoriaController,AuthController,BuzonController,ClientAuthController,ClientPortalController,ClientProjectController,ClienteController,CuestionarioController,DashboardController,EmpresaController,MantenimientoController,MobileAuthController,ProyectoController,ReporteController,SetupController,SolicitudController,PublicSolicitudController};
use Illuminate\Support\Facades\Route;

Route::get('health', fn () => ['status'=>'ok','service'=>'VITI Core API','time'=>now()->toIso8601String()]);

Route::prefix('v1')->group(function (): void {
    Route::get('setup/status', [SetupController::class,'status']);
    Route::post('setup', [SetupController::class,'store'])->middleware('throttle:login');

    Route::prefix('auth')->group(function (): void {
        Route::get('status', [AuthController::class,'status']);
        Route::post('login', [AuthController::class,'login'])->middleware('throttle:login');
        Route::post('cliente/registro', [ClientAuthController::class,'register'])->middleware('throttle:login');
    });

    Route::prefix('mobile')->group(function (): void {
        Route::post('login', [MobileAuthController::class,'login'])->middleware('throttle:login');
        Route::middleware('auth:sanctum')->post('logout', [MobileAuthController::class,'logout']);
    });


    Route::prefix('publico/solicitudes')->middleware('throttle:api')->group(function (): void {
        Route::get('{token}', [PublicSolicitudController::class,'show']);
        Route::put('{token}', [PublicSolicitudController::class,'save']);
        Route::post('{token}/enviar', [PublicSolicitudController::class,'submit']);
    });

    Route::middleware(['auth:sanctum','throttle:api'])->group(function (): void {
        Route::get('auth/me', [AuthController::class,'me']);
        Route::post('auth/logout', [AuthController::class,'logout']);
        Route::get('notificaciones/buzon', [BuzonController::class,'notifications']);
        Route::post('notificaciones/buzon/leer-todo', [BuzonController::class,'markAllRead']);

        Route::middleware('cliente')->prefix('mi')->group(function (): void {
            Route::get('perfil', [ClientPortalController::class,'profile']);
            Route::put('perfil', [ClientPortalController::class,'updateProfile']);
            Route::post('perfil/foto', [ClientPortalController::class,'uploadPhoto']);
            Route::get('solicitud', [ClientPortalController::class,'currentRequest']);
            Route::post('solicitud', [ClientPortalController::class,'startRequest']);
            Route::post('solicitudes/{solicitud}/sincronizar', [ClientPortalController::class,'syncFromQuestionnaire']);
            Route::get('proyecto', [ClientProjectController::class,'show']);
            Route::get('buzon', [BuzonController::class,'clientIndex']);
            Route::get('buzon/{conversacion}', [BuzonController::class,'clientShow']);
            Route::post('buzon', [BuzonController::class,'clientStart']);
            Route::post('buzon/{conversacion}/mensajes', [BuzonController::class,'clientSend']);
            Route::get('solicitudes/{solicitud}.pdf', [ReporteController::class,'clienteSolicitud']);
            Route::get('archivos/{archivo}/descargar', [ArchivoController::class,'clientDownload']);
        });

        Route::middleware('superadmin')->group(function (): void {
            Route::get('dashboard', DashboardController::class);

            Route::post('clientes/registro-completo', [ClienteController::class,'storeComplete']);
            Route::apiResource('clientes', ClienteController::class)->parameters(['clientes' => 'cliente']);
            Route::put('clientes/{cliente}/verificar-foto', [ClienteController::class,'verifyPhoto']);
            Route::apiResource('empresas', EmpresaController::class)->parameters(['empresas' => 'empresa']);
            Route::post('empresas/{empresa}/logo', [EmpresaController::class,'uploadLogo']);

            Route::get('cuestionarios', [CuestionarioController::class,'index']);
            Route::get('cuestionarios/{cuestionario}', [CuestionarioController::class,'show']);

            Route::apiResource('solicitudes', SolicitudController::class)->parameters(['solicitudes' => 'solicitud']);
            Route::put('solicitudes/{solicitud}/respuestas', [SolicitudController::class,'saveAnswers']);
            Route::post('solicitudes/{solicitud}/enviar', [SolicitudController::class,'submit']);

            Route::apiResource('proyectos', ProyectoController::class)->parameters(['proyectos' => 'proyecto']);
            Route::post('proyectos/{proyecto}/avances', [ProyectoController::class,'addProgress']);
            Route::apiResource('aplicaciones', AplicacionController::class)->parameters(['aplicaciones' => 'aplicacion']);
            Route::apiResource('mantenimientos', MantenimientoController::class)->parameters(['mantenimientos' => 'mantenimiento'])->except('show');

            Route::get('archivos', [ArchivoController::class,'index']);
            Route::post('archivos', [ArchivoController::class,'store']);
            Route::get('archivos/{archivo}/descargar', [ArchivoController::class,'download']);
            Route::delete('archivos/{archivo}', [ArchivoController::class,'destroy']);

            Route::get('buzon', [BuzonController::class,'adminIndex']);
            Route::get('buzon/{conversacion}', [BuzonController::class,'adminShow']);
            Route::post('buzon/{conversacion}/mensajes', [BuzonController::class,'adminSend']);
            Route::put('buzon/{conversacion}/estado', [BuzonController::class,'adminState']);

            Route::get('auditoria', [AuditoriaController::class,'index']);

            Route::prefix('reportes')->group(function (): void {
                Route::get('clientes.pdf', [ReporteController::class,'clientes']);
                Route::get('empresas.pdf', [ReporteController::class,'empresas']);
                Route::get('solicitudes/{solicitud}.pdf', [ReporteController::class,'solicitud']);
                Route::get('proyectos/{proyecto}.pdf', [ReporteController::class,'proyecto']);
            });
        });
    });
});
