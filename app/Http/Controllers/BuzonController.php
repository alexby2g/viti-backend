<?php

namespace App\Http\Controllers;

use App\Models\{Cliente,Conversacion,Mensaje,Usuario};
use App\Support\{Audit,FirebasePush};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuzonController extends Controller
{
    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Mensaje::query()
            ->whereNull('leido_at')
            ->whereHas('conversacion', fn ($q) => $q->where('canal_principal', true))
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
                'asunto' => 'Atención VITI',
                'mensaje' => str($mensaje->mensaje)->limit(95)->toString(),
                'created_at' => $mensaje->created_at,
            ];
        })->values();

        return response()->json(['data' => ['no_leidos' => $count, 'items' => $items]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Mensaje::query()
            ->whereNull('leido_at')
            ->whereHas('conversacion', fn ($q) => $q->where('canal_principal', true));

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
        $this->ensureAllClientChannels();

        $query = Conversacion::query()
            ->where('canal_principal', true)
            ->with([
                'cliente:id,nombre,telefono,foto_path',
                'responsable:id,nombre,apellido,rol,foto_path',
                'mensajes' => fn ($q) => $q->with('usuario:id,nombre,apellido,rol')->latest()->limit(1),
            ])
            ->withCount([
                'mensajes as no_leidos' => fn ($q) => $q
                    ->whereNull('leido_at')
                    ->whereHas('usuario', fn ($u) => $u->where('rol', 'cliente')),
            ])
            ->latest('ultimo_mensaje_at')
            ->latest('id');

        return response()->json($query->paginate(100));
    }

    public function adminShow(Conversacion $conversacion): JsonResponse
    {
        abort_unless($conversacion->canal_principal, 404, 'Este canal de atención ya no está activo.');
        $this->markConversationRead($conversacion, false);

        return response()->json(['data' => $this->loadChannel($conversacion)->setAttribute('no_leidos', 0)]);
    }

    public function adminSend(Request $request, Conversacion $conversacion): JsonResponse
    {
        abort_unless($conversacion->canal_principal, 404, 'Este canal de atención ya no está activo.');
        return $this->send($request, $conversacion);
    }

    public function adminState(Request $request, Conversacion $conversacion): JsonResponse
    {
        abort_unless($conversacion->canal_principal, 404, 'Este canal de atención ya no está activo.');
        $data = $request->validate(['estado' => ['required', 'in:abierta,cerrada']]);
        $conversacion->update($data);
        return response()->json(['data' => $conversacion]);
    }

    public function clientIndex(Request $request): JsonResponse
    {
        $channel = $this->ensurePrimaryChannel((int) $request->user()->cliente_id);
        $this->markConversationRead($channel, true);
        return response()->json(['data' => [$this->loadChannel($channel)->setAttribute('no_leidos', 0)]]);
    }

    public function clientShow(Request $request, Conversacion $conversacion): JsonResponse
    {
        abort_unless(
            $conversacion->canal_principal && (int) $conversacion->cliente_id === (int) $request->user()->cliente_id,
            403,
            'No tienes permiso para acceder a este canal de atención.'
        );

        $this->markConversationRead($conversacion, true);
        return response()->json(['data' => $this->loadChannel($conversacion)->setAttribute('no_leidos', 0)]);
    }

    public function clientStart(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mensaje' => ['required', 'string', 'max:5000'],
            'asunto' => ['nullable', 'string', 'max:180'],
        ]);
        $channel = $this->ensurePrimaryChannel((int) $request->user()->cliente_id);

        $message = Mensaje::create([
            'conversacion_id' => $channel->id,
            'usuario_id' => $request->user()->id,
            'mensaje' => $data['mensaje'],
        ]);
        $channel->update(['ultimo_mensaje_at' => now(), 'estado' => 'abierta']);
        Audit::log($request, 'mensaje_enviado', $channel, 'El cliente envió un mensaje a Atención VITI.');
        $this->pushMessage($request, $channel, $data['mensaje']);

        return response()->json(['data' => $this->loadChannel($channel->fresh())], 201);
    }

    public function clientSend(Request $request, Conversacion $conversacion): JsonResponse
    {
        abort_unless(
            $conversacion->canal_principal && (int) $conversacion->cliente_id === (int) $request->user()->cliente_id,
            403,
            'No tienes permiso para acceder a este canal de atención.'
        );

        return $this->send($request, $conversacion);
    }

    private function send(Request $request, Conversacion $conversacion): JsonResponse
    {
        if ($conversacion->estado === 'cerrada') $conversacion->update(['estado' => 'abierta']);
        $data = $request->validate(['mensaje' => ['required', 'string', 'max:5000']]);
        $mensaje = Mensaje::create([
            'conversacion_id' => $conversacion->id,
            'usuario_id' => $request->user()->id,
            'mensaje' => $data['mensaje'],
        ]);
        $conversacion->update(['ultimo_mensaje_at' => now()]);
        Audit::log($request, 'mensaje_enviado', $conversacion, 'Se envió un mensaje en Atención VITI.');
        $this->pushMessage($request, $conversacion, $data['mensaje']);

        return response()->json(['data' => $mensaje->load('usuario:id,nombre,apellido,rol')], 201);
    }

    private function pushMessage(Request $request, Conversacion $conversation, string $text): void
    {
        if ($request->user()->rol === 'cliente') {
            $targetIds = $conversation->responsable_usuario_id
                ? [$conversation->responsable_usuario_id]
                : Usuario::where('estado','activo')->where('rol','!=','cliente')->pluck('id')->all();
            $title = 'Nuevo mensaje de '.($request->user()->nombre ?: 'Cliente');
            $path = '/buzon?c='.$conversation->id;
        } else {
            $targetIds = Usuario::where('estado','activo')
                ->where('rol','cliente')
                ->where('cliente_id',$conversation->cliente_id)
                ->pluck('id')->all();
            $title = 'Nueva respuesta de Atención VITI';
            $path = '/mi-buzon?c='.$conversation->id;
        }

        FirebasePush::sendToUsers(
            $targetIds,
            $title,
            str($text)->limit(120)->toString(),
            ['type'=>'buzon','conversation_id'=>$conversation->id,'path'=>$path]
        );
    }

    private function ensureAllClientChannels(): void
    {
        Cliente::query()->select('id')->orderBy('id')->chunkById(100, function ($clients): void {
            foreach ($clients as $client) $this->ensurePrimaryChannel((int) $client->id);
        });
    }

    private function ensurePrimaryChannel(int $clientId): Conversacion
    {
        $channel = Conversacion::where('cliente_id',$clientId)->where('canal_principal',true)->first();
        if ($channel) return $channel;

        $adminId = Usuario::where('estado','activo')->where('rol','superadmin')->orderBy('id')->value('id');
        $channel = Conversacion::where('cliente_id',$clientId)->latest('ultimo_mensaje_at')->latest('id')->first();

        if ($channel) {
            Conversacion::where('cliente_id',$clientId)->update(['canal_principal'=>false]);
            $channel->update([
                'canal_principal'=>true,
                'responsable_usuario_id'=>$adminId,
                'asunto'=>'Atención VITI',
                'estado'=>'abierta',
            ]);
            return $channel->fresh();
        }

        return Conversacion::create([
            'cliente_id'=>$clientId,
            'responsable_usuario_id'=>$adminId,
            'asunto'=>'Atención VITI',
            'estado'=>'abierta',
            'canal_principal'=>true,
        ]);
    }

    private function loadChannel(Conversacion $conversation): Conversacion
    {
        return $conversation->load([
            'cliente:id,nombre,telefono,whatsapp,foto_path',
            'responsable:id,nombre,apellido,rol,foto_path',
            'solicitud:id,codigo,titulo,estado',
            'proyecto:id,codigo,nombre,fase,progreso',
            'mensajes.usuario:id,nombre,apellido,rol',
        ]);
    }

    private function markConversationRead(Conversacion $conversacion, bool $forClient): void
    {
        $query = $conversacion->mensajes()->whereNull('leido_at');
        if ($forClient) $query->whereHas('usuario', fn ($q) => $q->where('rol', '!=', 'cliente'));
        else $query->whereHas('usuario', fn ($q) => $q->where('rol', 'cliente'));
        $query->update(['leido_at' => now()]);
    }
}
