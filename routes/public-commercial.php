<?php

use App\Http\Controllers\SaasController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api')->get('publico/planes', [SaasController::class, 'publicPlanes']);
