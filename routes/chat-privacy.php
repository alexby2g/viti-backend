<?php

use App\Http\Controllers\{AtencionSesionController,BuzonController,SecureBuzonController};
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum','throttle:api'])->group(function (): void {
    Route::get('notificaciones/buzon',[SecureBuzonController::class,'notifications']);
    Route::post('notificaciones/buzon/leer-todo',[SecureBuzonController::class,'markAllRead']);

    Route::middleware('superadmin')->group(function (): void {
        Route::get('buzon',[BuzonController::class,'adminIndex']);
        Route::get('buzon/{conversacion}',[BuzonController::class,'adminShow']);
        Route::delete('buzon/{conversacion}',[BuzonController::class,'adminDeleteConversation']);
        Route::put('buzon/{conversacion}/estado',[BuzonController::class,'adminState']);
        Route::post('buzon/{conversacion}/mensajes',[BuzonController::class,'adminSend']);
        Route::put('buzon/{conversacion}/mensajes/{mensaje}',[BuzonController::class,'editMessage']);
        Route::delete('buzon/{conversacion}/mensajes/{mensaje}',[BuzonController::class,'deleteMessage']);
        Route::post('buzon/{conversacion}/presencia',[BuzonController::class,'presence']);

        Route::get('atencion/sesiones',[AtencionSesionController::class,'adminIndex']);
        Route::post('atencion/sesiones',[AtencionSesionController::class,'adminCreate']);
        Route::post('atencion/sesiones/{sesion}/aprobar',[AtencionSesionController::class,'approve']);
        Route::post('atencion/sesiones/{sesion}/rechazar',[AtencionSesionController::class,'reject']);
        Route::post('atencion/sesiones/{sesion}/finalizar',[AtencionSesionController::class,'finish']);
    });
});