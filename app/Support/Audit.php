<?php

namespace App\Support;

use App\Models\{Aplicacion,Auditoria,Empresa};
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    private const REDACTED = '[REDACTADO]';

    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'contrasena',
        'contraseña',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'api_key',
        'apikey',
        'client_secret',
        'secret',
        'secreto',
    ];

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

        $datos = self::sanitize($datos);

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

    private static function sanitize(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            $normalized = self::normalizeKey((string)$key);
            if (self::isSensitiveKey($normalized)) {
                $clean[$key] = self::REDACTED;
                continue;
            }

            $clean[$key] = is_array($value) ? self::sanitize($value) : $value;
        }

        return $clean;
    }

    private static function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['-', ' '], '_', trim($key)));
    }

    private static function isSensitiveKey(string $key): bool
    {
        if (in_array($key, self::SENSITIVE_KEYS, true)) return true;
        if (str_contains($key, 'password') || str_contains($key, 'contrasena') || str_contains($key, 'contraseña')) return true;
        if (str_ends_with($key, '_secret')) return true;
        return str_ends_with($key, '_token') && $key !== 'token_id';
    }
}
