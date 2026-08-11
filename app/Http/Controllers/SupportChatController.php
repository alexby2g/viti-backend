<?php

namespace App\Http\Controllers;

use App\Models\{Conversacion,Mensaje};
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SupportChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int)$request->user()->id;
        $query = Conversacion::query()
            ->where('responsable_usuario_id', $userId)
            ->where('contexto', 'viti')
            ->where('canal_principal', true)
            ->with([
                'cliente:id,nombre,telefono,foto_path',
                'empresa:id,nombre_comercial',
                'mensajes' => fn ($messages) => $messages->with('usuario:id,nombre,apellido,rol')->latest('id')->limit(1),
            ])
            ->withCount([
                'mensajes as no_leidos' => fn ($messages) => $messages
                    ->whereNull('leido_at')
                    ->whereHas('usuario', fn ($users) => $users->where('rol','cliente')),
            ])
            ->latest('ultimo_mensaje_at')
            ->latest('id');

        return response()->json($query->paginate(min(100,max(1,(int)$request->input('per_page',50)))));
    }

    public function show(Request $request, Conversacion $conversacion): JsonResponse
    {
        $this->assertAssigned($request, $conversacion);
        $conversacion->mensajes()
            ->whereNull('leido_at')
            ->whereHas('usuario', fn ($users) => $users->where('rol','cliente'))
            ->update(['entregado_at'=>now(),'leido_at'=>now()]);

        $conversacion->load([
            'cliente:id,nombre,telefono,whatsapp,foto_path',
            'empresa:id,nombre_comercial',
            'solicitud:id,codigo,titulo,estado',
            'proyecto:id,codigo,nombre,fase,progreso',
            'mensajes.usuario:id,nombre,apellido,rol',
        ]);
        $conversacion->setAttribute('contacto', $conversacion->cliente);
        $conversacion->setAttribute('no_leidos', 0);
        $conversacion->mensajes->transform(fn (Mensaje $message) => $this->present($message, $request));
        return response()->json(['data'=>$conversacion]);
    }

    public function send(Request $request, Conversacion $conversacion): JsonResponse
    {
        $this->assertAssigned($request, $conversacion);
        $data = $request->validate([
            'mensaje'=>['nullable','string','max:5000','required_without:archivo'],
            'archivo'=>['nullable','image','mimes:jpg,jpeg,png,webp','max:8192','required_without:mensaje'],
        ]);

        $path = null;
        if ($request->hasFile('archivo')) {
            $path = $request->file('archivo')->store('chat/soporte','private_uploads');
        }

        $message = Mensaje::create([
            'conversacion_id'=>$conversacion->id,
            'usuario_id'=>$request->user()->id,
            'tipo'=>$path ? 'imagen' : 'texto',
            'mensaje'=>trim((string)($data['mensaje'] ?? '')),
            'archivo_path'=>$path,
            'archivo_nombre'=>$request->file('archivo')?->getClientOriginalName(),
            'archivo_mime'=>$request->file('archivo')?->getClientMimeType(),
            'archivo_tamano'=>$request->file('archivo')?->getSize(),
            'entregado_at'=>now(),
        ]);
        $conversacion->update(['ultimo_mensaje_at'=>now(),'estado'=>'abierta']);
        Audit::log($request,'soporte_mensaje_enviado',$conversacion,'Soporte interno respondió una conversación asignada.');

        return response()->json(['data'=>$this->present($message->load('usuario:id,nombre,apellido,rol'), $request)],201);
    }

    public function download(Request $request, Conversacion $conversacion, Mensaje $mensaje)
    {
        $this->assertAssigned($request, $conversacion);
        abort_unless((int)$mensaje->conversacion_id === (int)$conversacion->id, 404, 'El archivo no pertenece a esta conversación.');
        abort_unless($mensaje->archivo_path && Storage::disk('private_uploads')->exists($mensaje->archivo_path), 404, 'Archivo no disponible.');
        return Storage::disk('private_uploads')->download($mensaje->archivo_path, $mensaje->archivo_nombre ?: basename($mensaje->archivo_path));
    }

    private function assertAssigned(Request $request, Conversacion $conversation): void
    {
        abort_unless(
            $conversation->contexto === 'viti'
            && $conversation->canal_principal
            && (int)$conversation->responsable_usuario_id === (int)$request->user()->id,
            403,
            'Esta conversación no está asignada a tu cuenta.'
        );
    }

    private function present(Mensaje $message, Request $request): Mensaje
    {
        $owns = (int)$message->usuario_id === (int)$request->user()->id;
        $message->setAttribute('es_mio',$owns);
        $message->setAttribute('puede_editar',false);
        $message->setAttribute('puede_eliminar',false);
        $message->setAttribute('eliminado',(bool)$message->eliminado_at);
        $message->setAttribute('estado_envio',$message->leido_at?'visto':($message->entregado_at?'entregado':'enviado'));
        return $message;
    }
}
