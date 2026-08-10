<?php

namespace App\Http\Controllers;

use App\Models\{ChatPresencia,Conversacion,Mensaje,Usuario};
use App\Services\ChatChannelService;
use App\Support\{Audit,FirebasePush};
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BuzonController extends Controller
{
    private const EDIT_MINUTES = 15;

    public function __construct(private ChatChannelService $channels) {}

    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $this->unreadFor($user)->with([
            'usuario:id,nombre,apellido,rol',
            'conversacion:id,cliente_id,asunto,contexto',
            'conversacion.cliente:id,nombre',
        ]);

        $count = (clone $query)->count();
        $items = $query->latest('created_at')->limit(8)->get()->map(function (Mensaje $message) use ($user): array {
            $isClient = $user->rol === 'cliente';
            $context = $message->conversacion?->contexto ?: ChatChannelService::VITI;
            $label = $this->channels->label($context);
            $preview = $this->preview($message);

            return [
                'id' => $message->id,
                'conversacion_id' => $message->conversacion_id,
                'contexto' => $context,
                'titulo' => $isClient ? 'Nueva respuesta de '.$label : 'Nuevo mensaje de '.($message->conversacion?->cliente?->nombre ?: 'cliente'),
                'asunto' => $label,
                'mensaje' => str($preview)->limit(95)->toString(),
                'path' => ($isClient ? $this->channels->clientPath($message->conversacion) : $this->channels->adminPath($message->conversacion)).'?c='.$message->conversacion_id,
                'created_at' => $message->created_at,
            ];
        })->values();

        return response()->json(['data' => ['no_leidos' => $count, 'items' => $items]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $this->unreadFor($request->user())->update(['leido_at' => now()]);
        return response()->json([
            'message' => $updated ? 'Notificaciones marcadas como leídas.' : 'No había notificaciones pendientes.',
            'data' => ['actualizados' => $updated],
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $context = $this->channels->context($request);
        $this->channels->ensureAdminChannels($context);

        $base = $this->channels->adminQuery($context);
        Mensaje::query()
            ->whereNull('entregado_at')
            ->whereHas('usuario', fn ($query) => $query->where('rol', 'cliente'))
            ->whereHas('conversacion', fn ($query) => $query->where('contexto', $context)->where('canal_principal', true))
            ->update(['entregado_at' => now()]);

        $query = $base->with([
                'cliente:id,nombre,telefono,foto_path',
                'empresa:id,nombre_comercial',
                'responsable:id,nombre,apellido,rol,foto_path',
                'mensajes' => fn ($messageQuery) => $messageQuery->with('usuario:id,nombre,apellido,rol')->latest()->limit(1),
            ])
            ->withCount([
                'mensajes as no_leidos' => fn ($messageQuery) => $messageQuery
                    ->whereNull('leido_at')
                    ->whereHas('usuario', fn ($userQuery) => $userQuery->where('rol', 'cliente')),
            ])
            ->latest('ultimo_mensaje_at')
            ->latest('id');

        return response()->json($query->paginate(min(100, max(1, (int) $request->input('per_page', 100)))));
    }

    public function adminShow(Request $request, Conversacion $conversacion): JsonResponse
    {
        $this->channels->assertContext($conversacion, $this->channels->context($request));
        $this->markConversationDelivered($conversacion, false);
        $this->markConversationRead($conversacion, false);
        return response()->json(['data' => $this->loadChannel($conversacion, $request->user())->setAttribute('no_leidos', 0)]);
    }

    public function adminSend(Request $request, Conversacion $conversacion): JsonResponse
    {
        $this->channels->assertContext($conversacion, $this->channels->context($request));
        return $this->send($request, $conversacion);
    }

    public function adminState(Request $request, Conversacion $conversacion): JsonResponse
    {
        $this->channels->assertContext($conversacion, $this->channels->context($request));
        $data = $request->validate(['estado' => ['required', 'in:abierta,cerrada']]);
        $conversacion->update($data);
        return response()->json(['data' => $conversacion]);
    }

    public function adminDeleteConversation(Request $request, Conversacion $conversacion): JsonResponse
    {
        abort_unless($request->user()?->isPlatformAdmin(), 403, 'Solo un administrador puede eliminar un chat completo.');
        $this->channels->assertContext($conversacion, $this->channels->context($request));

        $conversacion->llamadas()->whereIn('estado', ['llamando', 'activa'])->update(['estado' => 'cancelada', 'finalizada_at' => now()]);
        $conversacion->update(['eliminada_por_usuario_id' => $request->user()->id, 'canal_principal' => false]);
        $conversacion->delete();
        Audit::log($request, 'chat_eliminado', $conversacion, 'Se eliminó un chat completo del buzón '.$this->channels->label($conversacion->contexto).'.');

        return response()->json(['message' => 'El chat fue eliminado. Si el cliente vuelve a escribir, se abrirá una conversación nueva.']);
    }

    public function clientIndex(Request $request): JsonResponse
    {
        $channel = $this->channels->clientChannel($request);
        $this->markConversationDelivered($channel, true);
        $this->markConversationRead($channel, true);
        return response()->json(['data' => [$this->loadChannel($channel, $request->user())->setAttribute('no_leidos', 0)]]);
    }

    public function clientShow(Request $request, Conversacion $conversacion): JsonResponse
    {
        $this->channels->assertClient($request, $conversacion, $this->channels->context($request));
        $this->markConversationDelivered($conversacion, true);
        $this->markConversationRead($conversacion, true);
        return response()->json(['data' => $this->loadChannel($conversacion, $request->user())->setAttribute('no_leidos', 0)]);
    }

    public function clientStart(Request $request): JsonResponse
    {
        return $this->send($request, $this->channels->clientChannel($request));
    }

    public function clientSend(Request $request, Conversacion $conversacion): JsonResponse
    {
        $this->channels->assertClient($request, $conversacion, $this->channels->context($request));
        return $this->send($request, $conversacion);
    }

    public function editMessage(Request $request, Conversacion $conversacion, Mensaje $mensaje): JsonResponse
    {
        $this->authorizeConversation($request, $conversacion);
        $this->assertMessageBelongs($conversacion, $mensaje);
        abort_unless((int) $mensaje->usuario_id === (int) $request->user()->id, 403, 'Solo puedes editar tus propios mensajes.');
        abort_if($mensaje->eliminado_at, 422, 'Un mensaje eliminado ya no se puede editar.');
        abort_if($mensaje->created_at->lt(now()->subMinutes(self::EDIT_MINUTES)), 422, 'El plazo de 15 minutos para editar este mensaje ya terminó.');

        $data = $request->validate(['mensaje' => [$mensaje->archivo_path ? 'nullable' : 'required', 'string', 'max:5000']]);
        $mensaje->update(['mensaje' => trim((string) ($data['mensaje'] ?? '')), 'editado_at' => now()]);
        Audit::log($request, 'mensaje_editado', $conversacion, 'Se editó un mensaje dentro del plazo permitido.');

        return response()->json(['data' => $this->presentMessage($mensaje->fresh()->load('usuario:id,nombre,apellido,rol'), $request->user())]);
    }

    public function deleteMessage(Request $request, Conversacion $conversacion, Mensaje $mensaje): JsonResponse
    {
        $this->authorizeConversation($request, $conversacion);
        $this->assertMessageBelongs($conversacion, $mensaje);
        abort_if($mensaje->eliminado_at, 422, 'Este mensaje ya fue eliminado.');

        $ownsMessage = (int) $mensaje->usuario_id === (int) $request->user()->id;
        $insideWindow = $mensaje->created_at->gte(now()->subMinutes(self::EDIT_MINUTES));
        abort_unless($request->user()->isPlatformAdmin() || ($ownsMessage && $insideWindow), 403, 'Solo puedes eliminar tus propios mensajes durante 15 minutos.');

        if ($mensaje->archivo_path) {
            try { Storage::disk('private_uploads')->delete($mensaje->archivo_path); } catch (\Throwable) {}
        }

        $mensaje->update([
            'tipo' => 'eliminado',
            'mensaje' => '',
            'archivo_path' => null,
            'archivo_nombre' => null,
            'archivo_mime' => null,
            'archivo_tamano' => null,
            'eliminado_at' => now(),
            'eliminado_por_usuario_id' => $request->user()->id,
        ]);
        Audit::log($request, 'mensaje_eliminado', $conversacion, 'Se eliminó un mensaje del chat.');

        return response()->json(['data' => $this->presentMessage($mensaje->fresh()->load('usuario:id,nombre,apellido,rol'), $request->user())]);
    }

    public function presence(Request $request, Conversacion $conversacion): JsonResponse
    {
        $this->authorizeConversation($request, $conversacion);
        $data = $request->validate(['escribiendo' => ['nullable', 'boolean']]);

        ChatPresencia::updateOrCreate(
            ['conversacion_id' => $conversacion->id, 'usuario_id' => $request->user()->id],
            [
                'ultimo_ping_at' => now(),
                'escribiendo_hasta' => ($data['escribiendo'] ?? false) ? now()->addSeconds(6) : null,
            ]
        );
        $this->markConversationDelivered($conversacion, $request->user()->rol === 'cliente');

        $other = ChatPresencia::query()
            ->where('conversacion_id', $conversacion->id)
            ->where('usuario_id', '<>', $request->user()->id)
            ->whereHas('usuario', function ($query) use ($request, $conversacion): void {
                if ($request->user()->rol === 'cliente') {
                    $query->whereIn('rol', ['superadmin', 'administrador']);
                } else {
                    $query->where('rol', 'cliente')->where('cliente_id', $conversacion->cliente_id);
                }
            })
            ->with('usuario:id,nombre,apellido,rol')
            ->latest('ultimo_ping_at')
            ->first();

        return response()->json(['data' => [
            'en_linea' => (bool) ($other?->ultimo_ping_at?->gte(now()->subSeconds(20))),
            'escribiendo' => (bool) ($other?->escribiendo_hasta?->isFuture()),
            'usuario' => $other?->usuario,
        ]]);
    }

    private function send(Request $request, Conversacion $conversacion): JsonResponse
    {
        if ($conversacion->estado === 'cerrada') $conversacion->update(['estado' => 'abierta']);

        $data = $request->validate([
            'mensaje' => ['nullable', 'string', 'max:5000', 'required_without:archivo'],
            'archivo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'required_without:mensaje'],
            'client_request_id' => ['nullable', 'uuid'],
        ], [
            'mensaje.required_without' => 'Escribe un mensaje o adjunta una imagen.',
            'archivo.required_without' => 'Escribe un mensaje o adjunta una imagen.',
            'archivo.image' => 'El archivo debe ser una imagen válida.',
            'archivo.max' => 'La imagen no puede superar 8 MB.',
        ]);

        $requestId = $data['client_request_id'] ?? null;
        if ($requestId && ($existing = Mensaje::query()->where('client_request_id', $requestId)->first())) {
            abort_unless((int) $existing->conversacion_id === (int) $conversacion->id && (int) $existing->usuario_id === (int) $request->user()->id, 409, 'El identificador del mensaje ya fue utilizado.');
            return response()->json(['data' => $this->presentMessage($existing->load('usuario:id,nombre,apellido,rol'), $request->user())]);
        }

        $file = $request->file('archivo');
        $path = $file?->store('chat/'.$conversacion->contexto.'/'.now()->format('Y/m'), 'private_uploads');
        if ($file && !$path) return response()->json(['message' => 'No pudimos guardar la imagen adjunta.'], 500);

        try {
            $message = Mensaje::create([
                'conversacion_id' => $conversacion->id,
                'usuario_id' => $request->user()->id,
                'tipo' => $file ? 'imagen' : 'texto',
                'mensaje' => trim((string) ($data['mensaje'] ?? '')),
                'archivo_path' => $path,
                'archivo_nombre' => $file?->getClientOriginalName(),
                'archivo_mime' => $file?->getMimeType(),
                'archivo_tamano' => $file?->getSize(),
                'client_request_id' => $requestId,
            ]);
        } catch (QueryException $exception) {
            if ($path) {
                try { Storage::disk('private_uploads')->delete($path); } catch (\Throwable) {}
            }
            $existing = $requestId ? Mensaje::query()->where('client_request_id', $requestId)->first() : null;
            if (!$existing) throw $exception;
            return response()->json(['data' => $this->presentMessage($existing->load('usuario:id,nombre,apellido,rol'), $request->user())]);
        }

        $conversacion->update(['ultimo_mensaje_at' => now()]);
        $label = $this->channels->label($conversacion->contexto);
        Audit::log($request, 'mensaje_enviado', $conversacion, $file ? 'Se envió una imagen en '.$label.'.' : 'Se envió un mensaje en '.$label.'.');
        $this->pushMessage($request, $conversacion, $this->preview($message));

        return response()->json(['data' => $this->presentMessage($message->load('usuario:id,nombre,apellido,rol'), $request->user())], 201);
    }

    private function pushMessage(Request $request, Conversacion $conversation, string $text): void
    {
        $label = $this->channels->label($conversation->contexto);
        if ($request->user()->rol === 'cliente') {
            $targetIds = $conversation->responsable_usuario_id
                ? [$conversation->responsable_usuario_id]
                : Usuario::query()->where('estado', 'activo')->whereIn('rol', ['superadmin', 'administrador'])->pluck('id')->all();
            $title = 'Nuevo mensaje de '.($request->user()->nombre ?: 'Cliente').' · '.$label;
            $path = $this->channels->adminPath($conversation).'?c='.$conversation->id;
        } else {
            $targetIds = Usuario::query()->where('estado', 'activo')->where('rol', 'cliente')->where('cliente_id', $conversation->cliente_id)->pluck('id')->all();
            $title = 'Nueva respuesta de '.$label;
            $path = $this->channels->clientPath($conversation).'?c='.$conversation->id;
        }

        FirebasePush::sendToUsers($targetIds, $title, str($text)->limit(120)->toString(), [
            'type' => 'buzon',
            'contexto' => $conversation->contexto,
            'conversation_id' => $conversation->id,
            'path' => $path,
        ]);
    }

    private function loadChannel(Conversacion $conversation, Usuario $viewer): Conversacion
    {
        $conversation->load([
            'cliente:id,nombre,telefono,whatsapp,foto_path',
            'empresa:id,nombre_comercial',
            'aplicacion:id,nombre,slug',
            'responsable:id,nombre,apellido,rol,foto_path',
            'solicitud:id,codigo,titulo,estado',
            'proyecto:id,codigo,nombre,fase,progreso',
            'mensajes.usuario:id,nombre,apellido,rol',
            'sesionesAtencion' => fn ($query) => $query->latest('id')->limit(10),
        ]);

        $conversation->mensajes->transform(fn (Mensaje $message) => $this->presentMessage($message, $viewer));
        return $conversation;
    }

    private function presentMessage(Mensaje $message, Usuario $viewer): Mensaje
    {
        $owns = (int) $message->usuario_id === (int) $viewer->id;
        $insideWindow = $message->created_at?->gte(now()->subMinutes(self::EDIT_MINUTES)) ?? false;
        $deleted = (bool) $message->eliminado_at;

        $message->setAttribute('eliminado', $deleted);
        $message->setAttribute('puede_editar', $owns && $insideWindow && !$deleted);
        $message->setAttribute('puede_eliminar', !$deleted && ($viewer->isPlatformAdmin() || ($owns && $insideWindow)));
        $message->setAttribute('estado_envio', $message->leido_at ? 'visto' : ($message->entregado_at ? 'entregado' : 'enviado'));
        return $message;
    }

    private function markConversationDelivered(Conversacion $conversation, bool $forClient): void
    {
        $query = $conversation->mensajes()->whereNull('entregado_at');
        if ($forClient) $query->whereHas('usuario', fn ($userQuery) => $userQuery->where('rol', '!=', 'cliente'));
        else $query->whereHas('usuario', fn ($userQuery) => $userQuery->where('rol', 'cliente'));
        $query->update(['entregado_at' => now()]);
    }

    private function markConversationRead(Conversacion $conversation, bool $forClient): void
    {
        $query = $conversation->mensajes()->whereNull('leido_at');
        if ($forClient) $query->whereHas('usuario', fn ($userQuery) => $userQuery->where('rol', '!=', 'cliente'));
        else $query->whereHas('usuario', fn ($userQuery) => $userQuery->where('rol', 'cliente'));
        $query->update(['entregado_at' => now(), 'leido_at' => now()]);
    }

    private function authorizeConversation(Request $request, Conversacion $conversation): void
    {
        $context = $this->channels->context($request);
        if ($request->user()->rol === 'cliente') $this->channels->assertClient($request, $conversation, $context);
        else {
            abort_unless($request->user()->isPlatformAdmin(), 403, 'No tienes permiso para acceder a este chat.');
            $this->channels->assertContext($conversation, $context);
        }
    }

    private function assertMessageBelongs(Conversacion $conversation, Mensaje $message): void
    {
        abort_unless((int) $message->conversacion_id === (int) $conversation->id, 404, 'El mensaje no pertenece a esta conversación.');
    }

    private function unreadFor(Usuario $user)
    {
        $query = Mensaje::query()->whereNull('leido_at')->whereNull('eliminado_at')->whereHas('conversacion', fn ($conversationQuery) => $conversationQuery->where('canal_principal', true));
        if ($user->rol === 'cliente') {
            $query->whereHas('conversacion', fn ($conversationQuery) => $conversationQuery->where('cliente_id', (int) $user->cliente_id))
                ->whereHas('usuario', fn ($userQuery) => $userQuery->where('rol', '!=', 'cliente'));
        } else {
            $query->whereHas('usuario', fn ($userQuery) => $userQuery->where('rol', 'cliente'));
        }
        return $query;
    }

    private function preview(Mensaje $message): string
    {
        if ($message->eliminado_at) return 'Mensaje eliminado';
        $preview = trim((string) $message->mensaje);
        return $preview !== '' ? $preview : '📷 Imagen adjunta';
    }
}
