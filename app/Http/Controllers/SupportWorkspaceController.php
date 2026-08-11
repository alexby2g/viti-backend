<?php

namespace App\Http\Controllers;

use App\Models\{Conversacion,Mantenimiento,Proyecto,SolicitudSistema};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportWorkspaceController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $solicitudes = SolicitudSistema::query()
            ->where('asignado_a', $userId)
            ->with(['empresa:id,nombre_comercial','cliente:id,nombre,telefono'])
            ->latest('id')->limit(50)->get([
                'id','empresa_id','cliente_id','codigo','titulo','estado','prioridad','fecha_limite_deseada','updated_at'
            ]);

        $proyectos = Proyecto::query()
            ->where('responsable_id', $userId)
            ->with(['empresa:id,nombre_comercial','cliente:id,nombre,telefono'])
            ->latest('id')->limit(50)->get([
                'id','empresa_id','cliente_id','codigo','nombre','fase','estado','progreso','fecha_beta','fecha_entrega','updated_at'
            ]);

        $mantenimientos = Mantenimiento::query()
            ->where('asignado_a', $userId)
            ->with(['empresa:id,nombre_comercial','aplicacion:id,nombre'])
            ->latest('id')->limit(80)->get([
                'id','aplicacion_id','empresa_id','codigo','titulo','descripcion','tipo','prioridad','estado','updated_at'
            ]);

        $conversaciones = Conversacion::query()
            ->where('responsable_usuario_id', $userId)
            ->where('contexto', 'viti')
            ->where('canal_principal', true)
            ->with([
                'empresa:id,nombre_comercial',
                'cliente:id,nombre,telefono',
                'mensajes' => fn ($query) => $query->latest('id')->limit(1)->select('id','conversacion_id','usuario_id','mensaje','created_at'),
            ])
            ->withCount([
                'mensajes as no_leidos' => fn ($query) => $query
                    ->whereNull('leido_at')
                    ->whereHas('usuario', fn ($user) => $user->where('rol','cliente')),
            ])
            ->latest('ultimo_mensaje_at')
            ->limit(50)
            ->get(['id','cliente_id','empresa_id','asunto','estado','ultimo_mensaje_at']);

        return response()->json(['data'=>[
            'resumen'=>[
                'solicitudes'=>$solicitudes->count(),
                'proyectos'=>$proyectos->count(),
                'casos_abiertos'=>$mantenimientos->whereNotIn('estado',['resuelto','cerrado'])->count(),
                'mensajes_no_leidos'=>$conversaciones->sum('no_leidos'),
            ],
            'solicitudes'=>$solicitudes,
            'proyectos'=>$proyectos,
            'mantenimientos'=>$mantenimientos,
            'conversaciones'=>$conversaciones,
        ]]);
    }

    public function updateMaintenance(Request $request, Mantenimiento $mantenimiento): JsonResponse
    {
        abort_unless((int)$mantenimiento->asignado_a === (int)$request->user()->id, 403, 'Este caso no está asignado a tu cuenta.');
        $data = $request->validate([
            'estado'=>['required', Rule::in(['abierto','en_proceso','en_espera','resuelto','cerrado'])],
        ]);
        $mantenimiento->update($data);
        return response()->json(['data'=>$mantenimiento->fresh()->load(['empresa:id,nombre_comercial','aplicacion:id,nombre'])]);
    }
}
