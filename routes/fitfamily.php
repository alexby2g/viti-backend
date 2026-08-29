<?php

use App\Http\Controllers\{FitFamilyAuthController, FitFamilyController, FitFamilyGuestController, FitFamilyHealthController, FitFamilyOrderController, FitFamilyPagoController};
use Illuminate\Support\Facades\Route;

Route::prefix('fitfamily')->group(function (): void {
    Route::get('/health', FitFamilyHealthController::class);

    // Store público: no requiere cuenta para explorar ni comprar.
    Route::get('/catalogo', [FitFamilyController::class, 'catalog']);
    Route::get('/catalogo/{slug}', [FitFamilyController::class, 'category']);
    Route::get('/pedidos/{numero}', [FitFamilyOrderController::class, 'track']);
    Route::get('/carrito', [FitFamilyGuestController::class, 'index']);
    Route::post('/carrito/items', [FitFamilyGuestController::class, 'add']);
    Route::patch('/carrito/items/{item}', [FitFamilyGuestController::class, 'update']);
    Route::delete('/carrito/items/{item}', [FitFamilyGuestController::class, 'delete']);
    Route::post('/carrito/checkout', [FitFamilyGuestController::class, 'checkout']);

    Route::post('/auth/login', [FitFamilyAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [FitFamilyAuthController::class, 'me']);
        Route::post('/auth/logout', [FitFamilyAuthController::class, 'logout']);
        Route::get('/mis-pedidos', [FitFamilyController::class, 'history']);

        Route::prefix('admin')->group(function (): void {
            Route::get('/dashboard', [FitFamilyController::class, 'dashboard']);
            Route::get('/configuracion', [FitFamilyController::class, 'config']);
            Route::put('/configuracion', [FitFamilyController::class, 'saveConfig']);
            Route::get('/categorias', [FitFamilyController::class, 'categorias']);
            Route::post('/categorias', [FitFamilyController::class, 'storeCategoria']);
            Route::put('/categorias/{id}', [FitFamilyController::class, 'updateCategoria']);
            Route::get('/productos', [FitFamilyController::class, 'productos']);
            Route::post('/productos', [FitFamilyController::class, 'storeProducto']);
            Route::put('/productos/{id}', [FitFamilyController::class, 'updateProducto']);
            Route::patch('/productos/{id}/toggle', [FitFamilyController::class, 'toggleProducto']);
            Route::get('/clientes', [FitFamilyController::class, 'clientes']);
            Route::get('/pedidos', [FitFamilyController::class, 'pedidos']);
            Route::patch('/pedidos/{id}/estado', [FitFamilyOrderController::class, 'updateStatus']);
            Route::get('/pagos', [FitFamilyPagoController::class, 'index']);
            Route::patch('/pagos/{id}', [FitFamilyPagoController::class, 'update']);
        });
    });
});
