<?php

use App\Http\Controllers\{ProyectoSoporteController,SupportChatController,SupportWorkspaceController};
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum','throttle:api','soporte'])->prefix('soporte')->group(function (): void {
    Route::get('resumen', [SupportWorkspaceController::class,'overview']);
    Route::put('mantenimientos/{mantenimiento}/estado', [SupportWorkspaceController::class,'updateMaintenance']);

    Route::get('proyectos/{proyecto}/casos', [ProyectoSoporteController::class,'index']);
    Route::post('proyectos/{proyecto}/casos', [ProyectoSoporteController::class,'store']);
    Route::put('proyectos/{proyecto}/casos/{mantenimiento}', [ProyectoSoporteController::class,'update']);

    Route::get('buzon', [SupportChatController::class,'index']);
    Route::get('buzon/{conversacion}', [SupportChatController::class,'show']);
    Route::post('buzon/{conversacion}/mensajes', [SupportChatController::class,'send']);
    Route::get('buzon/{conversacion}/mensajes/{mensaje}/archivo', [SupportChatController::class,'download']);
});
