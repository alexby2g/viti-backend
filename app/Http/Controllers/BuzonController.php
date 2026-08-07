<?php

namespace App\Http\Controllers;

use App\Models\{Conversacion,Mensaje,Usuario};
use App\Support\{Audit,FirebasePush};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuzonController extends Controller
{
    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Mensaje::query()
            ->whereNull('leido_at')
            ->with([
                'usuario:id,nombre,apellido,rol',
                'conversacion:id,cliente_id,asunto',
                'conversacion.cliente:id,nombre',
            ]);

        if ($user->rol === 'cliente') {
            $query
                ->whereHas('conversacion', fn ($q) => $q->where('cliente_id', (int) $user->cliente_id))
                ->whereHas('usuario', fn ($q) => $q->where('rol', '!=', 'cliente'));
        } else {
            $query->whereHas('usuario', fn ($q) => $q->where('rol', 'cliente'));
        }

        $count = (clone $query)->count();
        $items = $query->latest('created_at')->limit(8)->get()->map(function (Mensaje $mensaje) use ($user): array {
            $isClient = $user->rol === 'cliente';
            $senderName = $mensaje->usuario?->nombre ?: ($isClient ? 'Equipo VITI' : 'Cliente');

            return [
                'id' => $mensaje->id,
                'conversacion_id' => $mensaje->conversacion_id,
                'titulo' => $isClient
                    ? 'Nueva respuesta del equipo VITI'
                    : 'Nuevo mensaje de '.($mensaje->conversacion?->cliente?->nombre ?: $senderName),
                'asunto' => $mensaje->conversacion?->asunto,
                'mensaje' => str($mensaje->mensaje)->limit(95)->toString(),
                'created_at' => $mensaje->created_at,
            ];
        })->values();

        return response()->json(['data' => ['no_leidos' => $count, 'items' => $items]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Mensaje::query()->whereNull('leido_at');

        if ($user->rol === 'cliente') {
            $query
                ->whereHas('conversacion', fn ($q) => $q->where('cliente_id', (int) $user->cliente_id))
                ->whereHas('usuario', fn ($q) => $q->where('rol', '!=', 'cliente'));
        } else {
            $query->whereHas('usuario', fn ($q) => $q->where('rol', 'cliente'));
        }

        $updated = $query->update(['leido_at' => now()]);

        return response()->json([
            'message' => $updated ? 'Notificaciones marcadas como leídas.' : 'No había notificaciones pendientes.',
            'data' => ['actualizados' => $updated],
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = Conversacion::with([
            'cliente:id,nombre,telefono,foto_path',
            'solicitud:id,codigo,titulo',
            'proyecto:id,codigo,nombre',
            'mensajes' => fn ($q) => $q->with('usuario:id,nombre,apellido,rol')->latest()->limit(1),
        ])->withCount([
            'mensajes as no_leidos' => fn ($q) => $q
                ->whereNull('leido_at')
                ->whereHas('usuario', fn ($u) => $u->where('rol', 'cliente')),
        ])->latest('ultimo_mensaje_at');

        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado'));
        }

        return response()->json($query->paginate(30));
    }

    public function adminShow(Conversacion $conversacion): JsonResponse
    {
        $this->markConversationRead($conversacion, false);

        return response()->json(['data' => $conversacion->load([
            'cliente:id,nombre,telefono,whatsapp,foto_path',
            'solicitud:id,codigo,titulo,estado',
            'proyecto:id,codigo,nombre,fase,progreso',
            'mensajes.usuario:id,nombre,apellido,rol',
        ])->setAttribute('no_leidos', 0)]);
    }

    public function adminSend(Request $request, Conversacion $conversacion): JsonResponse
    {
        return $this->send($request, $conversacion);
    }

    public function adminState(Request $request, Conversacion $conversacion): JsonResponse
    {
        $data = $request->validate(['estado' => ['required', 'in:abierta,cerrada']]);
        $conversacion->update($data);

        return response()->json(['data' => $conversacion]);
    }

    public function clientIndex(Request $request): JsonResponse
    {
        $clienteId = (int) $request->user()->cliente_id;
        $items = Conversacion::where('cliente_id', $clienteId)
            ->with([
                'solicitud:id,codigo,titulo',
                'proyecto:id,codigo,nombre',
                'mensajes' => fn ($q) => $q->with('usuario:id,nombre,apellido,rol')->latest()->limit(1),
            ])
            ->withCount([
                'mensajes as no_leidos' => fn ($q) => $q
                    ->whereNull('leido_at')
                    ->whereHas('usuario', fn ($u) => $u->where('rol', '!=', 'cliente')),
            ])
            ->latest('ultimo_mensaje_at')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function clientShow(Request $request, Conversacion $conversacion): JsonResponse
    {
        abort_unless(
            (int) $conversacion->cliente_id === (int) $request->user()->cliente_id,
            403,
            'No tienes permiso para acceder a esta conversación.'
        );

        $this->markConversationRead($conversacion, true);

        return response()->json(['data' => $conversacion->load([
            'solicitud:id,codigo,titulo',
            'proyecto:id,codigo,nombre',
            'mensajes.usuario:id,nombre,apellido,rol',
        ])->setAttribute('no_leidos', 0)]);
    }

    public function clientStart(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asunto' => ['required', 'string', 'max:180'],
            'mensaje' => ['required', 'string', 'max:5000'],
            'solicitud_id' => ['nullable', 'integer', 'exists:solicitudes_sistema,id'],
            'proyecto_id' => ['nullable', 'integer', 'exists:proyectos,id'],
        ]);
        $clienteId = (int) $request->user()->cliente_id;

        if (!empty($data['solicitud_id'])) {
            abort_unless(
                \App\Models\SolicitudSistema::whereKey($data['solicitud_id'])->where('cliente_id', $clienteId)->exists(),
                403,
                'No tienes permiso para usar esa solicitud.'
            );
        }

        if (!empty($data['proyecto_id'])) {
            abort_unless(
                \App\Models\Proyecto::whereKey($data['proyecto_id'])->where('cliente_id', $clienteId)->exists(),
                403,
                'No tienes permiso para usar ese proyecto.'
            );
        }

        $conversation = DB::transaction(function () use ($data, $clienteId, $request) {
            $c = Conversacion::create([
                'cliente_id' => $clienteId,
                'solicitud_id' => $data['solicitud_id'] ?? null,
                'proyecto_id' => $data['proyecto_id'] ?? null,
                'asunto' => $data['asunto'],
                'estado' => 'abierta',
                'ultimo_mensaje_at' => now(),
            ]);
            $c->mensajes()->create(['usuario_id' => $request->user()->id, 'mensaje' => $data['mensaje']]);

            return $c;
        });

        $clientName = $request->user()->nombre ?: 'Cliente';
        $adminIds = Usuario::query()
            ->where('estado', 'activo')
            ->where('rol', '!=', 'cliente')
            ->pluck('id')
            ->all();
        FirebasePush::sendToUsers(
            $adminIds,
            'Nuevo mensaje de '.$clientName,
            str($data['mensaje'])->limit(120)->toString(),
            ['type' => 'buzon', 'conversation_id' => $conversation->id, 'path' => '/buzon?c='.$conversation->id]
        );

        return response()->json(['data' => $conversation->load('mensajes.usuario')], 201);
    }

    public function clientSend(Request $request, Conversacion $conversacion): JsonResponse
    {
        abort_unless(
            (int) $conversacion->cliente_id === (int) $request->user()->cliente_id,
            403,
            'No tienes permiso para acceder a esta conversación.'
        );

        return $this->send($request, $conversacion);
    }

    private function send(Request $request, Conversacion $conversacion): JsonResponse
    {
        abort_if($conversacion->estado === 'cerrada', 422, 'La conversación está cerrada.');
        $data = $request->validate(['mensaje' => ['required', 'string', 'max:5000']]);
        $mensaje = Mensaje::create([
            'conversacion_id' => $conversacion->id,
            'usuario_id' => $request->user()->id,
            'mensaje' => $data['mensaje'],
        ]);
        $conversacion->update(['ultimo_mensaje_at' => now()]);
        Audit::log($request, 'mensaje_enviado', $conversacion, 'Se envió un mensaje en el buzón de VITI.');

        if ($request->user()->rol === 'cliente') {
            $targetIds = Usuario::query()
                ->where('estado', 'activo')
                ->where('rol', '!=', 'cliente')
                ->pluck('id')
                ->all();
            $title = 'Nuevo mensaje de '.($request->user()->nombre ?: 'Cliente');
            $path = '/buzon?c='.$conversacion->id;
        } else {
            $targetIds = Usuario::query()
                ->where('estado', 'activo')
                ->where('rol', 'cliente')
                ->where('cliente_id', $conversacion->cliente_id)
                ->pluck('id')
                ->all();
            $title = 'Nueva respuesta del equipo VITI';
            $path = '/mi-buzon?c='.$conversacion->id;
        }

        FirebasePush::sendToUsers(
            $targetIds,
            $title,
            str($data['mensaje'])->limit(120)->toString(),
            ['type' => 'buzon', 'conversation_id' => $conversacion->id, 'path' => $path]
        );

        return response()->json(['data' => $mensaje->load('usuario:id,nombre,apellido,rol')], 201);
    }

    private function markConversationRead(Conversacion $conversacion, bool $forClient): void
    {
        $query = $conversacion->mensajes()->whereNull('leido_at');

        if ($forClient) {
            $query->whereHas('usuario', fn ($q) => $q->where('rol', '!=', 'cliente'));
        } else {
            $query->whereHas('usuario', fn ($q) => $q->where('rol', 'cliente'));
        }

        $query->update(['leido_at' => now()]);
    }
}
