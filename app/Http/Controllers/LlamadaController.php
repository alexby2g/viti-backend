<?php

namespace App\Http\Controllers;

use App\Models\{Conversacion,Llamada,LlamadaSenal,Usuario};
use App\Support\FirebasePush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LlamadaController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversacion_id' => ['required','integer','exists:conversaciones,id'],
            'tipo' => ['required','in:audio,video'],
            'offer_sdp' => ['required','string','max:200000'],
        ]);

        $user = $request->user();
        $conversation = Conversacion::with(['cliente','responsable'])->findOrFail($data['conversacion_id']);

        if ($user->rol === 'cliente') {
            abort_unless((int) $conversation->cliente_id === (int) $user->cliente_id, 403, 'No tienes permiso para llamar desde este canal.');
            $target = $conversation->responsable;
            if (!$target || $target->estado !== 'activo') {
                $target = Usuario::where('estado','activo')->where('rol','superadmin')->orderBy('id')->first();
            }
        } else {
            $target = Usuario::where('estado','activo')
                ->where('rol','cliente')
                ->where('cliente_id',$conversation->cliente_id)
                ->orderBy('id')
                ->first();
        }

        if (!$target) {
            return response()->json(['message'=>'La otra persona todavía no tiene una cuenta activa para recibir llamadas.'], 422);
        }

        Llamada::query()
            ->where('conversacion_id',$conversation->id)
            ->whereIn('estado',['llamando','activa'])
            ->update(['estado'=>'cancelada','finalizada_at'=>now()]);

        $call = Llamada::create([
            'conversacion_id' => $conversation->id,
            'cliente_id' => $conversation->cliente_id,
            'iniciada_por_usuario_id' => $user->id,
            'receptor_usuario_id' => $target->id,
            'tipo' => $data['tipo'],
            'estado' => 'llamando',
            'offer_sdp' => $this->normalizeSdp($data['offer_sdp']),
        ]);

        $isClientCaller = $user->rol === 'cliente';
        $title = $isClientCaller
            ? (($data['tipo']==='video'?'Videollamada':'Llamada').' de '.($user->nombre ?: 'Cliente'))
            : (($data['tipo']==='video'?'Videollamada':'Llamada').' de Atención VITI');
        $path = $isClientCaller
            ? '/buzon?c='.$conversation->id.'&call='.$call->id
            : '/mi-buzon?c='.$conversation->id.'&call='.$call->id;

        FirebasePush::sendToUsers(
            [$target->id],
            $title,
            $data['tipo']==='video' ? 'Tienes una videollamada entrante.' : 'Tienes una llamada de voz entrante.',
            [
                'type'=>'llamada',
                'call_id'=>$call->id,
                'call_type'=>$call->tipo,
                'conversation_id'=>$conversation->id,
                'path'=>$path,
            ]
        );

        return response()->json(['data'=>$this->present($call)], 201);
    }

    public function incoming(Request $request): JsonResponse
    {
        $call = Llamada::query()
            ->where('receptor_usuario_id',$request->user()->id)
            ->where('estado','llamando')
            ->latest('id')
            ->first();

        return response()->json(['data'=>$call ? $this->present($call) : null]);
    }

    public function show(Request $request, Llamada $llamada): JsonResponse
    {
        $this->authorizeCall($request, $llamada);
        return response()->json(['data'=>$this->present($llamada)]);
    }

    public function answer(Request $request, Llamada $llamada): JsonResponse
    {
        $this->authorizeCall($request, $llamada);
        abort_unless((int) $llamada->receptor_usuario_id === (int) $request->user()->id, 403, 'Solo el receptor puede contestar esta llamada.');
        abort_unless($llamada->estado === 'llamando', 422, 'La llamada ya no está disponible.');

        $data = $request->validate(['answer_sdp'=>['required','string','max:200000']]);
        $llamada->update([
            'answer_sdp'=>$this->normalizeSdp($data['answer_sdp']),
            'estado'=>'activa',
            'contestada_at'=>now(),
        ]);

        return response()->json(['data'=>$this->present($llamada->fresh())]);
    }

    public function signal(Request $request, Llamada $llamada): JsonResponse
    {
        $this->authorizeCall($request, $llamada);
        abort_if(in_array($llamada->estado,['finalizada','rechazada','cancelada','perdida'],true), 422, 'La llamada ya finalizó.');

        $data = $request->validate([
            'tipo'=>['required','in:ice'],
            'payload'=>['required','array'],
        ]);

        $signal = LlamadaSenal::create([
            'llamada_id'=>$llamada->id,
            'usuario_id'=>$request->user()->id,
            'tipo'=>$data['tipo'],
            'payload'=>$data['payload'],
        ]);

        return response()->json(['data'=>$signal], 201);
    }

    public function signals(Request $request, Llamada $llamada): JsonResponse
    {
        $this->authorizeCall($request, $llamada);
        $since = max(0, (int) $request->query('since',0));
        $items = $llamada->senales()
            ->where('id','>',$since)
            ->where('usuario_id','!=',$request->user()->id)
            ->orderBy('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data'=>[
                'llamada'=>$this->present($llamada->fresh()),
                'senales'=>$items,
            ],
        ]);
    }

    public function finish(Request $request, Llamada $llamada): JsonResponse
    {
        $this->authorizeCall($request, $llamada);
        $data = $request->validate(['estado'=>['nullable','in:finalizada,rechazada,cancelada']]);
        $estado = $data['estado'] ?? 'finalizada';

        if (!in_array($llamada->estado,['finalizada','rechazada','cancelada','perdida'],true)) {
            $llamada->update(['estado'=>$estado,'finalizada_at'=>now()]);
        }

        return response()->json(['data'=>$this->present($llamada->fresh())]);
    }

    private function authorizeCall(Request $request, Llamada $call): void
    {
        $user = $request->user();
        if ($user->rol === 'cliente') {
            abort_unless((int) $call->cliente_id === (int) $user->cliente_id, 403, 'No tienes permiso para acceder a esta llamada.');
            return;
        }

        abort_unless(
            (int) $call->iniciada_por_usuario_id === (int) $user->id ||
            (int) $call->receptor_usuario_id === (int) $user->id ||
            $user->rol === 'superadmin',
            403,
            'No tienes permiso para acceder a esta llamada.'
        );
    }

    private function present(Llamada $call): array
    {
        $call->loadMissing([
            'cliente:id,nombre,telefono,foto_path',
            'iniciador:id,nombre,apellido,rol,foto_path',
            'receptor:id,nombre,apellido,rol,foto_path',
        ]);

        return [
            'id'=>$call->id,
            'conversacion_id'=>$call->conversacion_id,
            'cliente_id'=>$call->cliente_id,
            'tipo'=>$call->tipo,
            'estado'=>$call->estado,
            'offer_sdp'=>$this->normalizeSdp($call->offer_sdp),
            'answer_sdp'=>$this->normalizeSdp($call->answer_sdp),
            'contestada_at'=>$call->contestada_at,
            'finalizada_at'=>$call->finalizada_at,
            'created_at'=>$call->created_at,
            'iniciada_por_usuario_id'=>$call->iniciada_por_usuario_id,
            'receptor_usuario_id'=>$call->receptor_usuario_id,
            'cliente'=>$call->cliente,
            'iniciador'=>$call->iniciador,
            'receptor'=>$call->receptor,
        ];
    }

    private function normalizeSdp(?string $sdp): ?string
    {
        if ($sdp === null || $sdp === '') return $sdp;

        // Algunos navegadores/WebViews son estrictos con CRLF. PostgreSQL conserva el
        // texto, pero normalizamos siempre antes de guardar y antes de responder.
        $sdp = str_replace(["\\r\\n", "\\n", "\\r"], ["\n", "\n", "\n"], $sdp);
        $sdp = str_replace(["\r\n", "\r"], "\n", $sdp);
        $lines = explode("\n", $sdp);
        $lines = array_map(static fn ($line) => rtrim($line, " \t"), $lines);
        while ($lines && end($lines) === '') array_pop($lines);

        return implode("\r\n", $lines)."\r\n";
    }
}
