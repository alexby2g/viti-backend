<?php

namespace App\Support;

use App\Models\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function log(Request $request, string $accion, ?Model $entidad = null, ?string $descripcion = null, array $datos = []): void
    {
        Auditoria::create([
            'usuario_id' => $request->user()?->id,
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
