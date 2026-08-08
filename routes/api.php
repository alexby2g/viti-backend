<?php

use App\Http\Controllers\{AplicacionController,ArchivoController,AtencionSesionController,AuditoriaController,AuthController,BuzonController,ClientAuthController,ClientPortalController,ClientProjectController,ClienteController,CuestionarioController,DashboardController,EmpresaController,LlamadaController,MantenimientoController,MobileAuthController,OnboardingController,PeluqueriaController,ProyectoController,PushDeviceController,ReporteController,SetupController,SolicitudController,StorageController,PublicSolicitudController};
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

    Route::prefix('publico/registro')->middleware('throttle:api')->group(function (): void {
        Route::get('{token}', [OnboardingController::class,'showInvitation']);
        Route::post('{token}', [OnboardingController::class,'register'])->middleware('throttle:login');
    });

    Route::post('publico/solicitudes', [PublicSolicitudController::class,'start'])->middleware('throttle:login');
    Route::prefix('publico/solicitudes')->middleware('throttle:api')->group(function (): void {
        Route::get('{token}', [PublicSolicitudController::class,'show']);
        Route::put('{token}', [PublicSolicitudController::class,'save']);
        Route::post('{token}/enviar', [PublicSolicitudController::class,'submit']);
    });

    Route::middleware(['auth:sanctum','throttle:api'])->group(function (): void {
        Route::get('auth/me', [AuthController::class,'me']);
        Route::post('auth/perfil/foto', [AuthController::class,'uploadPhoto']);
        Route::post('auth/logout', [AuthController::class,'logout']);
        Route::get('notificaciones/buzon', [BuzonController::class,'notifications']);
        Route::post('notificaciones/buzon/leer-todo', [BuzonController::class,'markAllRead']);
        Route::post('push/dispositivo', [PushDeviceController::class,'store']);
        Route::delete('push/dispositivo', [PushDeviceController::class,'destroy']);

        Route::get('llamadas/entrante', [LlamadaController::class,'incoming']);
        Route::post('llamadas', [LlamadaController::class,'store']);
        Route::get('llamadas/{llamada}', [LlamadaController::class,'show']);
        Route::post('llamadas/{llamada}/contestar', [LlamadaController::class,'answer']);
        Route::post('llamadas/{llamada}/senal', [LlamadaController::class,'signal']);
        Route::get('llamadas/{llamada}/senales', [LlamadaController::class,'signals']);
        Route::post('llamadas/{llamada}/finalizar', [LlamadaController::class,'finish']);

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

            Route::get('atencion/sesiones', [AtencionSesionController::class,'clientStatus']);
            Route::post('atencion/solicitudes', [AtencionSesionController::class,'clientRequest']);
            Route::post('atencion/sesiones/{sesion}/cancelar', [AtencionSesionController::class,'clientCancel']);

            Route::get('solicitudes/{solicitud}.pdf', [ReporteController::class,'clienteSolicitud']);
            Route::get('archivos/{archivo}/descargar', [ArchivoController::class,'clientDownload']);
        });

        Route::middleware('superadmin')->group(function (): void {
            Route::get('dashboard', DashboardController::class);
            Route::get('almacenamiento/estado', [StorageController::class,'status']);
            Route::post('invitaciones-clientes', [OnboardingController::class,'createInvitation']);

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

            Route::prefix('apps/peluqueria')->group(function (): void {
                Route::get('resumen', [PeluqueriaController::class,'resumen']);

                Route::get('clientes', [PeluqueriaController::class,'clientes']);
                Route::post('clientes', [PeluqueriaController::class,'guardarCliente']);
                Route::put('clientes/{id}', [PeluqueriaController::class,'actualizarCliente']);
                Route::delete('clientes/{id}', [PeluqueriaController::class,'eliminarCliente']);

                Route::get('servicios', [PeluqueriaController::class,'servicios']);
                Route::post('servicios', [PeluqueriaController::class,'guardarServicio']);
                Route::put('servicios/{id}', [PeluqueriaController::class,'actualizarServicio']);
                Route::delete('servicios/{id}', [PeluqueriaController::class,'eliminarServicio']);

                Route::get('personal', [PeluqueriaController::class,'personal']);
                Route::post('personal', [PeluqueriaController::class,'guardarPersonal']);
                Route::put('personal/{id}', [PeluqueriaController::class,'actualizarPersonal']);
                Route::delete('personal/{id}', [PeluqueriaController::class,'eliminarPersonal']);

                Route::get('citas', [PeluqueriaController::class,'citas']);
                Route::post('citas', [PeluqueriaController::class,'guardarCita']);
                Route::put('citas/{id}', [PeluqueriaController::class,'actualizarCita']);
                Route::delete('citas/{id}', [PeluqueriaController::class,'eliminarCita']);

                Route::get('atenciones', [PeluqueriaController::class,'atenciones']);
                Route::post('atenciones', [PeluqueriaController::class,'iniciarAtencion']);
                Route::post('atenciones/{id}/finalizar', [PeluqueriaController::class,'finalizarAtencion']);
                Route::post('atenciones/{id}/pagos', [PeluqueriaController::class,'registrarPago']);
                Route::get('historial', [PeluqueriaController::class,'historial']);
            });

            Route::get('archivos', [ArchivoController::class,'index']);
            Route::post('archivos', [ArchivoController::class,'store']);
            Route::get('archivos/{archivo}/descargar', [ArchivoController::class,'download']);
            Route::delete('archivos/{archivo}', [ArchivoController::class,'destroy']);

            Route::get('buzon', [BuzonController::class,'adminIndex']);
            Route::get('buzon/{conversacion}', [BuzonController::class,'adminShow']);
            Route::post('buzon/{conversacion}/mensajes', [BuzonController::class,'adminSend']);
            Route::put('buzon/{conversacion}/estado', [BuzonController::class,'adminState']);

            Route::get('atencion/sesiones', [AtencionSesionController::class,'adminIndex']);
            Route::post('atencion/sesiones', [AtencionSesionController::class,'adminCreate']);
            Route::post('atencion/sesiones/{sesion}/aprobar', [AtencionSesionController::class,'approve']);
            Route::post('atencion/sesiones/{sesion}/rechazar', [AtencionSesionController::class,'reject']);
            Route::post('atencion/sesiones/{sesion}/finalizar', [AtencionSesionController::class,'finish']);

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
