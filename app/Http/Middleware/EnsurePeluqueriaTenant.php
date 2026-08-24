<?php

namespace App\Http\Middleware;

use App\Models\Aplicacion;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePeluqueriaTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->is('api/v1/apps/peluqueria/*')) {
            return $next($request);
        }

        $tenants = app(TenantContext::class);
        $empresa = $tenants->resolve($request);

        $allowed = Aplicacion::query()
            ->where('empresa_id', $empresa->id)
            ->whereNotIn('estado', ['retirado'])
            ->whereHas('catalogo', fn ($q) => $q->where('clave', 'peluqueria')->where('activo', true))
            ->exists();

        abort_unless($allowed, 403, 'Este negocio no tiene Peluquería VITI habilitada.');

        $action = (string) ($request->route()?->getActionMethod() ?? '');
        $module = match ($action) {
            'resumen' => 'inicio',
            'clientes', 'guardarCliente', 'actualizarCliente', 'eliminarCliente' => 'clientes',
            'servicios', 'guardarServicio', 'actualizarServicio', 'eliminarServicio' => 'ordenes',
            'personal', 'guardarPersonal', 'actualizarPersonal', 'eliminarPersonal' => 'tecnicos',
            'citas', 'guardarCita', 'actualizarCita', 'eliminarCita' => 'agenda',
            'atenciones', 'iniciarAtencion', 'finalizarAtencion' => 'ordenes',
            'registrarPago' => 'pagos',
            'historial' => 'historial',
            default => null,
        };

        if ($module !== null) {
            $tenants->assertModule($empresa, $module);
            $tenants->assertCanUse($request->user(), $empresa, $module);
        }

        // El controlador legado de Peluquería sigue leyendo empresa_id del request.
        // Lo sobrescribimos con el tenant verificado para impedir manipulación manual.
        $request->merge(['empresa_id' => (int) $empresa->id]);
        $request->attributes->set('viti_peluqueria_modules', $tenants->effectiveModules($request->user(), $empresa));

        return $next($request);
    }
}
