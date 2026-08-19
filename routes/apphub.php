<?php

use App\Http\Controllers\AppHubController;
use Illuminate\Support\Facades\Route;

Route::get('/applications', [AppHubController::class, 'index']);
Route::post('/applications', [AppHubController::class, 'store']);
Route::put('/applications/{id}', [AppHubController::class, 'update']);
Route::post('/applications/{id}/clone', [AppHubController::class, 'clone']);
Route::get('/applications/{id}/users', [AppHubController::class, 'users']);
Route::post('/applications/{id}/users', [AppHubController::class, 'integrateUser']);
