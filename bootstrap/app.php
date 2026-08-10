<?php

use App\Http\Middleware\{EnsureClient,EnsureElectrofrioCustomer,EnsurePeluqueriaTenant,EnsurePlatformAdmin,EnsureSuperAdmin,ResolveTenant,SecurityHeaders};
use Illuminate\Foundation\Application;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')->prefix('api/v1')->group(base_path('routes/commercial.php'));
            Route::middleware('api')->prefix('api/v1')->group(base_path('routes/peluqueria-admin.php'));
            Route::middleware('api')->prefix('api/v1')->group(base_path('routes/servicio-tecnico.php'));
            Route::middleware('api')->prefix('api/v1')->group(base_path('routes/chat-privacy.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->statefulApi();
        $middleware->append(SecurityHeaders::class);
        $middleware->append(EnsurePeluqueriaTenant::class);
        $middleware->alias([
            'superadmin'=>EnsureSuperAdmin::class,
            'platform_admin'=>EnsurePlatformAdmin::class,
            'cliente'=>EnsureClient::class,
            'electrofrio_customer'=>EnsureElectrofrioCustomer::class,
            'tenant'=>ResolveTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (ValidationException $e, $request) {
            if (!$request->is('api/*')) return null;
            return response()->json(['message'=>'Revisa los datos ingresados.','errors'=>$e->errors()],422);
        });
        $exceptions->render(function (AuthenticationException $e, $request) {
            if (!$request->is('api/*')) return null;
            return response()->json(['message'=>'Tu sesión no está activa. Inicia sesión nuevamente.'],401);
        });
        $exceptions->render(function (ModelNotFoundException $e, $request) {
            if (!$request->is('api/*')) return null;
            return response()->json(['message'=>'El registro solicitado no existe o ya no está disponible.'],404);
        });
        $exceptions->render(function (QueryException $e, $request) {
            if (!$request->is('api/*')) return null;
            report($e);
            return response()->json(['message'=>'No pudimos guardar la información por un conflicto en la base de datos. Revisa los datos e inténtalo nuevamente.'],500);
        });
        $exceptions->render(function (HttpExceptionInterface $e, $request) {
            if (!$request->is('api/*')) return null;
            $message = $e->getMessage() ?: match($e->getStatusCode()) {
                403=>'No tienes permiso para realizar esta acción.',
                404=>'El recurso solicitado no fue encontrado.',
                409=>'No se pudo completar la operación porque existe un conflicto con los datos actuales.',
                default=>'No se pudo completar la operación solicitada.',
            };
            return response()->json(['message'=>$message],$e->getStatusCode());
        });
        $exceptions->render(function (\Throwable $e, $request) {
            if (!$request->is('api/*')) return null;
            report($e);
            return response()->json(['message'=>'Ocurrió un error interno en VITI. Inténtalo nuevamente. Si el problema continúa, comunícate con soporte.'],500);
        });
    })
    ->create();
