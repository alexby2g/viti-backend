<?php
use App\Http\Controllers\{FitFamilyAuthController,FitFamilyController,FitFamilyPagoController};
use Illuminate\Support\Facades\Route;

Route::prefix('fitfamily')->group(function():void{
    Route::get('/health',fn()=>['status'=>'ok','service'=>'VITI FitFamily','time'=>now()->toIso8601String()]);
    Route::get('/catalogo',[FitFamilyController::class,'catalog']);
    Route::get('/catalogo/{slug}',[FitFamilyController::class,'category']);
    Route::post('/auth/login',[FitFamilyAuthController::class,'login'])->middleware('throttle:login');
    Route::middleware('auth:sanctum')->group(function():void{
        Route::get('/auth/me',[FitFamilyAuthController::class,'me']);
        Route::post('/auth/logout',[FitFamilyAuthController::class,'logout']);
        Route::get('/carrito',[FitFamilyController::class,'cartIndex']);
        Route::get('/mis-pedidos',[FitFamilyController::class,'history']);
        Route::post('/carrito/items',[FitFamilyController::class,'addToCart']);
        Route::patch('/carrito/items/{item}',[FitFamilyController::class,'updateCart']);
        Route::delete('/carrito/items/{item}',[FitFamilyController::class,'deleteCart']);
        Route::post('/carrito/checkout',[FitFamilyController::class,'checkout']);
        Route::prefix('admin')->group(function():void{
            Route::get('/dashboard',[FitFamilyController::class,'dashboard']);
            Route::get('/configuracion',[FitFamilyController::class,'config']);
            Route::put('/configuracion',[FitFamilyController::class,'saveConfig']);
            Route::get('/categorias',[FitFamilyController::class,'categorias']);
            Route::post('/categorias',[FitFamilyController::class,'storeCategoria']);
            Route::put('/categorias/{id}',[FitFamilyController::class,'updateCategoria']);
            Route::get('/productos',[FitFamilyController::class,'productos']);
            Route::post('/productos',[FitFamilyController::class,'storeProducto']);
            Route::put('/productos/{id}',[FitFamilyController::class,'updateProducto']);
            Route::patch('/productos/{id}/toggle',[FitFamilyController::class,'toggleProducto']);
            Route::get('/clientes',[FitFamilyController::class,'clientes']);
            Route::get('/pedidos',[FitFamilyController::class,'pedidos']);
            Route::patch('/pedidos/{id}/estado',[FitFamilyController::class,'updatePedido']);
            Route::get('/pagos',[FitFamilyPagoController::class,'index']);
            Route::patch('/pagos/{id}',[FitFamilyPagoController::class,'update']);
        });
    });
});
