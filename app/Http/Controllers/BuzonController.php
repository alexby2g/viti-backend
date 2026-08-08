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
            $preview = trim((string)$mensaje->mensaje);
            if ($preview === '' && $mensaje->archivo_path) $preview = '📷 Imagen adjunta';

            return [
                'id' => $mensaje->id,
                'conversacion_id' => $mensaje->conversacion_id,
                'titulo' => $isClient
                    ? 'Nueva respuesta del equipo VITI'
                    : 'Nuevo mensaje de '.($mensaje->conversacion?->cliente?->nombre ?: $senderName),
                'asunto' => 'Atención VITI',
                'mensaje' => str($preview)->limit(95)->toString(),
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
        $channel = $this->ensurePrimaryChannel((int) $request->user()->cliente_id);
        return $this->send($request, $channel);
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

        $data = $request->validate([
            'mensaje' => ['nullable','string','max:5000','required_without:archivo'],
            'archivo' => ['nullable','image','mimes:jpg,jpeg,png,webp','max:8192','required_without:mensaje'],
        ], [
            'mensaje.required_without' => 'Escribe un mensaje o adjunta una imagen.',
            'archivo.required_without' => 'Escribe un mensaje o adjunta una imagen.',
            'archivo.image' => 'El archivo debe ser una imagen válida.',
            'archivo.max' => 'La imagen no puede superar 8 MB.',
        ]);

        $file = $request->file('archivo');
        $path = null;
        if ($file) {
            $path = $file->store('chat/'.now()->format('Y/m'), 'private_uploads');
            if (!$path) return response()->json(['message'=>'No pudimos guardar la imagen adjunta.'], 500);
        }

        $mensaje = Mensaje::create([
            'conversacion_id' => $conversacion->id,
            'usuario_id' => $request->user()->id,
            'tipo' => $file ? 'imagen' : 'texto',
            'mensaje' => trim((string)($data['mensaje'] ?? '')),
            'archivo_path' => $path,
            'archivo_nombre' => $file?->getClientOriginalName(),
            'archivo_mime' => $file?->getMimeType(),
            'archivo_tamano' => $file?->getSize(),
        ]);

        $conversacion->update(['ultimo_mensaje_at' => now()]);
        Audit::log($request, 'mensaje_enviado', $conversacion, $file ? 'Se envió una imagen en Atención VITI.' : 'Se envió un mensaje en Atención VITI.');

        $preview = trim((string)$mensaje->mensaje);
        if ($preview === '') $preview = '📷 Imagen adjunta';
        $this->pushMessage($request, $conversacion, $preview);

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
            'sesionesAtencion' => fn ($q) => $q->latest('id')->limit(10),
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
