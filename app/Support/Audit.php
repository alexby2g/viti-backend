<?php

namespace App\Support;

use App\Models\{Aplicacion,Auditoria,Empresa};
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function log(Request $request, string $accion, ?Model $entidad = null, ?string $descripcion = null, array $datos = []): void
    {
        $empresaId = $request->attributes->get('viti_empresa_id');
        $aplicacionId = null;
        $requestId = $request->attributes->get('viti_request_id');
        $clientRequestId = $request->attributes->get('viti_client_request_id');

        if ($entidad instanceof Empresa) $empresaId = $entidad->id;
        elseif ($entidad?->getAttribute('empresa_id')) $empresaId = $entidad->getAttribute('empresa_id');

        if ($entidad instanceof Aplicacion) $aplicacionId = $entidad->id;
        elseif ($entidad?->getAttribute('aplicacion_id')) $aplicacionId = $entidad->getAttribute('aplicacion_id');

        $trace = array_filter([
            'request_id' => $requestId,
            'client_request_id' => $clientRequestId,
        ], fn ($value) => $value !== null && $value !== '');
        if ($trace) $datos = ['_trace' => $trace] + $datos;

        Auditoria::create([
            'usuario_id' => $request->user()?->id,
            'empresa_id' => $empresaId,
            'aplicacion_id' => $aplicacionId,
            'accion' => $accion,
            'entidad_tipo' => $entidad ? $entidad::class : null,
            'entidad_id' => $entidad?->getKey(),
            'descripcion' => $descripcion,
            'datos' => $datos ?: null,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
