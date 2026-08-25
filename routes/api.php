<?php

use App\Http\Controllers\{AgrActionController,AgrVoiceController,AplicacionController,ArchivoController,AtencionSesionController,AuditoriaController,AuthController,BuzonController,ClientAppsController,ClientAuthController,ClientElectrofrioController,ClientPortalController,ClientProjectController,ClienteController,CuestionarioController,DashboardController,ElectrofrioController,ElectrofrioCustomerAuthController,ElectrofrioCustomerPortalController,EmpresaController,LlamadaController,MantenimientoController,MobileAuthController,OnboardingController,PeluqueriaController,ProyectoController,PushDeviceController,ReporteController,SetupController,SolicitudController,StorageController,PublicSolicitudController,UsuarioController};
use Illuminate\Support\Facades\Route;

Route::get('health', fn () => ['status'=>'ok','service'=>'VITI Core API','time'=>now()->toIso8601String()]);

Route::prefix('v1')->group(function (): void {
    Route::get('setup/status', [SetupController::class,'status']);
    Route::post('setup', [SetupController::class,'store'])->middleware('throttle:login');

    Route::prefix('auth')->group(function (): void {
        Route::get('status', [AuthController::class,'status']);
        Route::post('login', [AuthController::class,'login'])->middleware('throttle:login');
        Route::post('cliente/registro', [ClientAuthController::class,'register'])->middleware('throttle:login');
        Route::post('electrofrio/login',[ElectrofrioCustomerAuthController::class,'login'])->middleware('throttle:login');
    });

    Route::prefix('mobile')->group(function (): void {
        Route::post('login', [MobileAuthController::class,'login'])->middleware('throttle:login');
        Route::middleware('auth:sanctum')->post('logout', [MobileAuthController::class,'logout']);
    });

    Route::prefix('publico/registro')->middleware('throttle:api')->group(function (): void {
        Route::get('{token}', [OnboardingController::class,'showInvitation']);
        Route::post('{token}', [OnboardingController::class,'register'])->middleware('throttle:login');
    });

    Route::post('publico/solicitudes', [PublicSolicitudController::class,'start'])->middleware('throttle:5,1');

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

        Route::middleware('electrofrio_customer')->prefix('portal/electrofrio')->group(function():void{
            Route::get('me',[ElectrofrioCustomerAuthController::class,'me']);
            Route::get('resumen',[ElectrofrioCustomerPortalController::class,'resumen']);
            Route::get('buzon',[BuzonController::class,'clientIndex'])->defaults('chat_context','electrofrio');
            Route::get('buzon/{conversacion}',[BuzonController::class,'clientShow'])->defaults('chat_context','electrofrio');
            Route::post('buzon',[BuzonController::class,'clientStart'])->defaults('chat_context','electrofrio');
            Route::post('buzon/{conversacion}/mensajes',[BuzonController::class,'clientSend'])->defaults('chat_context','electrofrio');
            Route::put('buzon/{conversacion}/mensajes/{mensaje}',[BuzonController::class,'editMessage'])->defaults('chat_context','electrofrio');
            Route::delete('buzon/{conversacion}/mensajes/{mensaje}',[BuzonController::class,'deleteMessage'])->defaults('chat_context','electrofrio');
            Route::post('buzon/{conversacion}/presencia',[BuzonController::class,'presence'])->defaults('chat_context','electrofrio');
            Route::get('atencion/sesiones',[AtencionSesionController::class,'clientStatus'])->defaults('chat_context','electrofrio');
            Route::post('atencion/solicitudes',[AtencionSesionController::class,'clientRequest'])->defaults('chat_context','electrofrio');
            Route::post('atencion/sesiones/{sesion}/cancelar',[AtencionSesionController::class,'clientCancel'])->defaults('chat_context','electrofrio');
        });

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
            Route::get('aplicaciones', [ClientAppsController::class,'index']);
            Route::get('aplicaciones/peluqueria', [ClientAppsController::class,'peluqueria']);
            Route::get('buzon', [BuzonController::class,'clientIndex']);
            Route::get('buzon/{conversacion}', [BuzonController::class,'clientShow']);
            Route::post('buzon', [BuzonController::class,'clientStart']);
            Route::post('buzon/{conversacion}/mensajes', [BuzonController::class,'clientSend']);
            Route::put('buzon/{conversacion}/mensajes/{mensaje}', [BuzonController::class,'editMessage']);
            Route::delete('buzon/{conversacion}/mensajes/{mensaje}', [BuzonController::class,'deleteMessage']);
            Route::post('buzon/{conversacion}/presencia', [BuzonController::class,'presence']);

            Route::get('atencion/sesiones', [AtencionSesionController::class,'clientStatus']);
            Route::post('atencion/solicitudes', [AtencionSesionController::class,'clientRequest']);
            Route::post('atencion/sesiones/{sesion}/cancelar', [AtencionSesionController::class,'clientCancel']);

            Route::prefix('apps/electrofrio')->group(function (): void {
                Route::get('estado', [ClientElectrofrioController::class, 'estado']);
                Route::get('resumen', [ClientElectrofrioController::class, 'resumen']);
                Route::get('clientes', [ClientElectrofrioController::class, 'clientes']);
                Route::post('clientes', [ClientElectrofrioController::class, 'guardarCliente']);
                Route::put('clientes/{id}', [ClientElectrofrioController::class, 'actualizarCliente']);
                Route::delete('clientes/{id}', [ClientElectrofrioController::class, 'eliminarCliente']);
                Route::get('equipos', [ClientElectrofrioController::class, 'equipos']);
                Route::post('equipos', [ClientElectrofrioController::class, 'guardarEquipo']);
                Route::put('equipos/{id}', [ClientElectrofrioController::class, 'actualizarEquipo']);
                Route::delete('equipos/{id}', [ClientElectrofrioController::class, 'eliminarEquipo']);
                Route::get('tecnicos', [ClientElectrofrioController::class, 'tecnicos']);
                Route::get('usuarios-negocio', [ClientElectrofrioController::class, 'usuariosNegocio']);
                Route::post('tecnicos', [ClientElectrofrioController::class,'guardarTecnico']);
                Route::put('tecnicos/{id}', [ClientElectrofrioController::class,'actualizarTecnico']);
                Route::delete('tecnicos/{id}', [ClientElectrofrioController::class,'eliminarTecnico']);
                Route::post('clientes/{id}/acceso', [ClientElectrofrioController::class,'guardarAccesoCliente']);
                Route::delete('clientes/{id}/acceso', [ClientElectrofrioController::class,'revocarAccesoCliente']);
                Route::get('materiales', [ClientElectrofrioController::class,'materiales']);
                Route::post('materiales', [ClientElectrofrioController::class,'guardarMaterial']);
                Route::put('materiales/{id}', [ClientElectrofrioController::class,'actualizarMaterial']);
                Route::delete('materiales/{id}', [ClientElectrofrioController::class,'eliminarMaterial']);
                Route::get('ordenes', [ClientElectrofrioController::class,'ordenes']);
                Route::post('ordenes', [ClientElectrofrioController::class,'guardarOrden']);
                Route::put('ordenes/{id}', [ClientElectrofrioController::class,'actualizarOrden']);
                Route::delete('ordenes/{id}', [ClientElectrofrioController::class,'eliminarOrden']);
                Route::post('ordenes/{id}/decision', [ClientElectrofrioController::class,'decision']);
                Route::post('ordenes/{id}/finalizar', [ClientElectrofrioController::class,'finalizar']);
                Route::post('ordenes/{id}/materiales', [ClientElectrofrioController::class,'usarMaterial']);
                Route::delete('ordenes/{orderId}/materiales/{materialId}', [ClientElectrofrioController::class,'quitarMaterial']);
                Route::post('ordenes/{id}/pagos', [ClientElectrofrioController::class,'registrarPago']);
                Route::get('pagos', [ClientElectrofrioController::class,'pagos']);
                Route::get('garantias', [ClientElectrofrioController::class,'garantias']);
                Route::get('historial', [ClientElectrofrioController::class,'historial']);
                Route::get('buzon', [BuzonController::class,'businessIndex'])->defaults('chat_context','electrofrio');
                Route::get('buzon/{conversacion}', [BuzonController::class,'businessShow'])->defaults('chat_context','electrofrio');
                Route::post('buzon/{conversacion}/mensajes', [BuzonController::class,'businessSend'])->defaults('chat_context','electrofrio');
                Route::put('buzon/{conversacion}/mensajes/{mensaje}', [BuzonController::class,'editMessage'])->defaults('chat_context','electrofrio');
                Route::delete('buzon/{conversacion}/mensajes/{mensaje}', [BuzonController::class,'deleteMessage'])->defaults('chat_context','electrofrio');
                Route::post('buzon/{conversacion}/presencia', [BuzonController::class,'presence'])->defaults('chat_context','electrofrio');
                Route::put('buzon/{conversacion}/estado',[BuzonController::class,'businessState'])->defaults('chat_context','electrofrio');
                Route::delete('buzon/{conversacion}',[BuzonController::class,'businessDeleteConversation'])->defaults('chat_context','electrofrio');
                Route::get('atencion/sesiones',[AtencionSesionController::class,'adminIndex'])->defaults('chat_context','electrofrio');
                Route::post('atencion/sesiones',[AtencionSesionController::class,'adminCreate'])->defaults('chat_context','electrofrio');
                Route::post('atencion/sesiones/{sesion}/aprobar',[AtencionSesionController::class,'approve'])->defaults('chat_context','electrofrio');
                Route::post('atencion/sesiones/{sesion}/rechazar',[AtencionSesionController::class,'reject'])->defaults('chat_context','electrofrio');
                Route::post('atencion/sesiones/{sesion}/finalizar',[AtencionSesionController::class,'finish'])->defaults('chat_context','electrofrio');
            });

            Route::get('solicitudes/{solicitud}.pdf', [ReporteController::class,'clienteSolicitud']);
            Route::get('archivos/{archivo}/descargar', [ArchivoController::class,'clientDownload']);
        });

        Route::middleware('platform_admin')->group(function (): void {
            Route::get('dashboard', DashboardController::class);
            Route::post('agr/voice', AgrVoiceController::class);
            Route::post('agr/acciones/crear-cliente', [AgrActionController::class,'confirmCreateClient']);
            Route::get('almacenamiento/estado', [StorageController::class,'status'])->middleware('superadmin');
            Route::post('invitaciones-clientes', [OnboardingController::class,'createInvitation']);
            Route::post('solicitudes/{solicitud}/invitacion', [OnboardingController::class,'createSolicitudInvitation']);
            Route::post('solicitudes/{solicitud}/invitacion/reenviar', [OnboardingController::class,'resendSolicitudInvitation']);
            Route::delete('solicitudes/{solicitud}/invitacion', [OnboardingController::class,'revokeSolicitudInvitation']);
            Route::post('clientes/registro-completo', [ClienteController::class,'storeComplete']);
            Route::apiResource('clientes', ClienteController::class)->parameters(['clientes'=>'cliente']);
            Route::put('clientes/{cliente}/verificar-foto', [ClienteController::class,'verifyPhoto']);
            Route::apiResource('usuarios', UsuarioController::class)->parameters(['usuarios'=>'usuario'])->except('show')->middleware('superadmin');
            Route::apiResource('empresas', EmpresaController::class)->parameters(['empresas'=>'empresa']);
            Route::post('empresas/{empresa}/usuarios', [EmpresaController::class,'assignUser'])->middleware('superadmin');
            Route::delete('empresas/{empresa}/usuarios/{usuario}', [EmpresaController::class,'unassignUser'])->middleware('superadmin');
            Route::post('empresas/{empresa}/propietario', [EmpresaController::class,'assignUser'])->defaults('rol_negocio','propietario')->middleware('superadmin');
            Route::delete('empresas/{empresa}/propietario', function (\Illuminate\Http\Request $request, EmpresaController $controller, \App\Models\Empresa $empresa): \Illuminate\Http\JsonResponse {
                $owner = $empresa->usuarios()->wherePivot('rol_negocio','propietario')->first();
                if ($owner) return $controller->unassignUser($request, $empresa, $owner);
                return response()->json(['data'=>$empresa->fresh()->load('usuarios')]);
            })->middleware('superadmin');
            Route::post('empresas/{empresa}/logo', [EmpresaController::class,'uploadLogo']);
            Route::get('cuestionarios', [CuestionarioController::class,'index']);
            Route::get('cuestionarios/{cuestionario}', [CuestionarioController::class,'show']);
            Route::apiResource('solicitudes', SolicitudController::class)->parameters(['solicitudes'=>'solicitud']);
            Route::put('solicitudes/{solicitud}/respuestas', [SolicitudController::class,'saveAnswers']);
            Route::post('solicitudes/{solicitud}/enviar', [SolicitudController::class,'submit']);
            Route::post('solicitudes/{solicitud}/rechazar', [SolicitudController::class,'reject']);
            Route::get('solicitudes/{solicitud}/historial', [SolicitudController::class,'timeline']);
            Route::apiResource('proyectos', ProyectoController::class)->parameters(['proyectos'=>'proyecto']);
            Route::post('proyectos/{proyecto}/avances', [ProyectoController::class,'addProgress']);
            Route::apiResource('aplicaciones', AplicacionController::class)->parameters(['aplicaciones'=>'aplicacion']);
            Route::apiResource('mantenimientos', MantenimientoController::class)->parameters(['mantenimientos'=>'mantenimiento'])->except('show');
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
            Route::prefix('apps/electrofrio')->group(function (): void {
                Route::get('resumen', [ElectrofrioController::class,'resumen']);
                Route::get('clientes', [ElectrofrioController::class,'clientes']);
                Route::post('clientes', [ElectrofrioController::class,'guardarCliente']);
                Route::put('clientes/{id}', [ElectrofrioController::class,'actualizarCliente']);
                Route::delete('clientes/{id}', [ElectrofrioController::class,'eliminarCliente']);
                Route::get('equipos', [ElectrofrioController::class,'equipos']);
                Route::post('equipos', [ElectrofrioController::class,'guardarEquipo']);
                Route::put('equipos/{id}', [ElectrofrioController::class,'actualizarEquipo']);
                Route::delete('equipos/{id}', [ElectrofrioController::class,'eliminarEquipo']);
                Route::get('tecnicos', [ElectrofrioController::class,'tecnicos']);
                Route::get('usuarios-negocio', [ElectrofrioController::class,'usuariosNegocio']);
                Route::post('tecnicos', [ElectrofrioController::class,'guardarTecnico']);
                Route::put('tecnicos/{id}', [ElectrofrioController::class,'actualizarTecnico']);
                Route::delete('tecnicos/{id}', [ElectrofrioController::class,'eliminarTecnico']);
                Route::post('clientes/{id}/acceso', [ElectrofrioController::class,'guardarAccesoCliente']);
                Route::delete('clientes/{id}/acceso', [ElectrofrioController::class,'revocarAccesoCliente']);
                Route::get('materiales', [ElectrofrioController::class,'materiales']);
                Route::post('materiales', [ElectrofrioController::class,'guardarMaterial']);
                Route::put('materiales/{id}', [ElectrofrioController::class,'actualizarMaterial']);
                Route::delete('materiales/{id}', [ElectrofrioController::class,'eliminarMaterial']);
                Route::get('ordenes', [ElectrofrioController::class,'ordenes']);
                Route::post('ordenes', [ElectrofrioController::class,'guardarOrden']);
                Route::put('ordenes/{id}', [ElectrofrioController::class,'actualizarOrden']);
                Route::delete('ordenes/{id}', [ElectrofrioController::class,'eliminarOrden']);
                Route::post('ordenes/{id}/decision', [ElectrofrioController::class,'decision']);
                Route::post('ordenes/{id}/finalizar', [ElectrofrioController::class,'finalizar']);
                Route::post('ordenes/{id}/materiales', [ElectrofrioController::class,'usarMaterial']);
                Route::delete('ordenes/{orderId}/materiales/{materialId}', [ElectrofrioController::class,'quitarMaterial']);
                Route::post('ordenes/{id}/pagos', [ElectrofrioController::class,'registrarPago']);
                Route::get('pagos', [ElectrofrioController::class,'pagos']);
                Route::get('garantias', [ElectrofrioController::class,'garantias']);
                Route::get('historial', [ElectrofrioController::class,'historial']);
                Route::get('buzon', [BuzonController::class,'businessIndex'])->defaults('chat_context','electrofrio');
                Route::get('buzon/{conversacion}', [BuzonController::class,'businessShow'])->defaults('chat_context','electrofrio');
                Route::post('buzon/{conversacion}/mensajes', [BuzonController::class,'businessSend'])->defaults('chat_context','electrofrio');
                Route::put('buzon/{conversacion}/mensajes/{mensaje}', [BuzonController::class,'editMessage'])->defaults('chat_context','electrofrio');
                Route::delete('buzon/{conversacion}/mensajes/{mensaje}', [BuzonController::class,'deleteMessage'])->defaults('chat_context','electrofrio');
                Route::post('buzon/{conversacion}/presencia', [BuzonController::class,'presence'])->defaults('chat_context','electrofrio');
                Route::put('buzon/{conversacion}/estado', [BuzonController::class,'businessState'])->defaults('chat_context','electrofrio');
                Route::delete('buzon/{conversacion}', [BuzonController::class,'businessDeleteConversation'])->defaults('chat_context','electrofrio');
                Route::get('atencion/sesiones', [AtencionSesionController::class,'adminIndex'])->defaults('chat_context','electrofrio');
                Route::post('atencion/sesiones', [AtencionSesionController::class,'adminCreate'])->defaults('chat_context','electrofrio');
                Route::post('atencion/sesiones/{sesion}/aprobar', [AtencionSesionController::class,'approve'])->defaults('chat_context','electrofrio');
                Route::post('atencion/sesiones/{sesion}/rechazar', [AtencionSesionController::class,'reject'])->defaults('chat_context','electrofrio');
                Route::post('atencion/sesiones/{sesion}/finalizar', [AtencionSesionController::class,'finish'])->defaults('chat_context','electrofrio');
            });
            Route::get('archivos', [ArchivoController::class,'index']);
            Route::post('archivos', [ArchivoController::class,'store']);
            Route::get('archivos/{archivo}/descargar', [ArchivoController::class,'download']);
            Route::delete('archivos/{archivo}', [ArchivoController::class,'destroy']);
            Route::get('buzon', [BuzonController::class,'adminIndex']);
            Route::get('buzon/{conversacion}', [BuzonController::class,'adminShow']);
            Route::post('buzon/{conversacion}/mensajes', [BuzonController::class,'adminSend']);
            Route::put('buzon/{conversacion}/mensajes/{mensaje}', [BuzonController::class,'editMessage']);
            Route::delete('buzon/{conversacion}/mensajes/{mensaje}', [BuzonController::class,'deleteMessage']);
            Route::post('buzon/{conversacion}/presencia', [BuzonController::class,'presence']);
            Route::put('buzon/{conversacion}/estado', [BuzonController::class,'adminState']);
            Route::delete('buzon/{conversacion}', [BuzonController::class,'adminDeleteConversation']);
            Route::get('atencion/sesiones', [AtencionSesionController::class,'adminIndex']);
            Route::post('atencion/sesiones', [AtencionSesionController::class,'adminCreate']);
            Route::post('atencion/sesiones/{sesion}/aprobar', [AtencionSesionController::class,'approve']);
            Route::post('atencion/sesiones/{sesion}/rechazar', [AtencionSesionController::class,'reject']);
            Route::post('atencion/sesiones/{sesion}/finalizar', [AtencionSesionController::class,'finish']);
            Route::get('auditoria', [AuditoriaController::class,'index'])->middleware('superadmin');
            Route::prefix('reportes')->group(function (): void {
                Route::get('clientes.pdf', [ReporteController::class,'clientes']);
                Route::get('empresas.pdf', [ReporteController::class,'empresas']);
                Route::get('solicitudes/{solicitud}.pdf', [ReporteController::class,'solicitud']);
                Route::get('proyectos/{proyecto}.pdf', [ReporteController::class,'proyecto']);
            });
        });
    });
});
