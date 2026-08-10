<?php

namespace App\Http\Middleware;

use App\Models\Aplicacion;
use App\Services\SubscriptionAccessService;
use Closure;
use Illuminate\Http\Request;

class EnsureElectrofrioCustomer
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user?->rol === 'cliente_negocio' && $user?->estado === 'activo' && $user?->electrofrio_cliente_id, 403, 'Este acceso es exclusivo para clientes activos de Electrofrío.');
        abort_unless($user->electrofrioCliente?->activo, 403, 'Tu acceso a Electrofrío no está activo.');
        $app=Aplicacion::query()->where('empresa_id',$user->electrofrioCliente->empresa_id)->where('estado','activo')->where('acceso_cliente',true)
            ->whereHas('catalogo',fn($query)=>$query->where('clave','electrofrio'))->latest('id')->first();
        abort_unless($app,403,'El portal de Electrofrío no está disponible en este momento.');
        app(SubscriptionAccessService::class)->assertCanUse($app);
        return $next($request);
    }
}
