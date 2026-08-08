<?php

namespace App\Http\Controllers;

use App\Models\{AtencionSesion,Conversacion,Usuario};
use App\Support\{Audit,FirebasePush};
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtencionSesionController extends Controller
{
    public function clientStatus(Request $request): JsonResponse
    {
        $clientId = (int) $request->user()->cliente_id;
        $conversation = $this->primaryConversation($clientId);

        $items = AtencionSesion::query()
            ->where('conversacion_id', $conversation->id)
            ->whereIn('estado', ['solicitada','aprobada'])
            ->latest('id')
            ->limit(10)
            ->get();

        $active = $items->first(fn (AtencionSesion $s) => $s->estaHabilitadaAhora());
        $pending = $items->firstWhere('estado', 'solicitada');
        $upcoming = $items
            ->where('estado', 'aprobada')
            ->filter(fn (AtencionSesion $s) => $s->habilitada_desde && $s->habilitada_desde->isFuture())
            ->sortBy('habilitada_desde')
            ->first();

        return response()->json(['data' => [
            'puede_llamar' => (bool) $active,
            'sesion_activa' => $active,
            'solicitud_pendiente' => $pending,
            'proxima_sesion' => $upcoming,
        ]]);
    }

    public function clientRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'modalidad' => ['required','in:audio,video,pantalla'],
            'motivo' => ['required','string','max:1200'],
        ], [
            'modalidad.required' => 'Selecciona el tipo de atención que necesitas.',
            'motivo.required' => 'Cuéntanos brevemente qué necesitas revisar.',
        ]);

        $clientId = (int) $request->user()->cliente_id;
        $conversation = $this->primaryConversation($clientId);

        $alreadyPending = AtencionSesion::query()
            ->where('conversacion_id', $conversation->id)
            ->where('estado', 'solicitada')
            ->exists();
        if ($alreadyPending) {
            return response()->json(['message'=>'Ya tienes una solicitud de atención pendiente de revisión.'], 422);
        }

        $session = AtencionSesion::create([
            'conversacion_id' => $conversation->id,
            'cliente_id' => $clientId,
            'solicitada_por_usuario_id' => $request->user()->id,
            'modalidad' => $data['modalidad'],
            'estado' => 'solicitada',
            'motivo' => trim($data['motivo']),
        ]);

        Audit::log($request, 'atencion_solicitada', $session, 'El cliente solicitó una sesión de atención.');

        $targetIds = $conversation->responsable_usuario_id
            ? [$conversation->responsable_usuario_id]
            : Usuario::where('estado','activo')->where('rol','!=','cliente')->pluck('id')->all();

        FirebasePush::sendToUsers(
            $targetIds,
            'Nueva solicitud de atención',
            ($request->user()->nombre ?: 'Un cliente').' solicita '.($data['modalidad'] === 'pantalla' ? 'asistencia con pantalla' : ($data['modalidad'] === 'video' ? 'videollamada' : 'llamada de voz')).'.',
            ['type'=>'atencion','conversation_id'=>$conversation->id,'session_id'=>$session->id,'path'=>'/buzon?c='.$conversation->id]
        );

        return response()->json(['message'=>'Solicitud enviada. El equipo VITI la revisará antes de habilitar la llamada.','data'=>$session], 201);
    }

    public function clientCancel(Request $request, AtencionSesion $sesion): JsonResponse
    {
        abort_unless((int)$sesion->cliente_id === (int)$request->user()->cliente_id, 403, 'No tienes permiso para modificar esta solicitud.');
        abort_unless(in_array($sesion->estado, ['solicitada','aprobada'], true), 422, 'Esta sesión ya no puede cancelarse.');
        $sesion->update(['estado'=>'cancelada']);
        return response()->json(['message'=>'Solicitud cancelada.','data'=>$sesion->fresh()]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = AtencionSesion::query()
            ->with(['cliente:id,nombre,telefono,foto_path','solicitante:id,nombre,apellido,rol','aprobador:id,nombre,apellido,rol'])
            ->latest('id');

        if ($request->filled('conversacion_id')) {
            $query->where('conversacion_id', (int)$request->input('conversacion_id'));
        }

        return response()->json(['data'=>$query->limit(30)->get()]);
    }

    public function adminCreate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversacion_id' => ['required','integer','exists:conversaciones,id'],
            'modalidad' => ['required','in:audio,video,pantalla'],
            'motivo' => ['nullable','string','max:1200'],
            'programada_para' => ['nullable','date'],
            'duracion_minutos' => ['nullable','integer','min:15','max:180'],
        ]);

        $conversation = Conversacion::findOrFail($data['conversacion_id']);
        $session = $this->createApproved(
            $request,
            $conversation,
            $data['modalidad'],
            $data['motivo'] ?? 'Sesión habilitada por Atención VITI.',
            $data['programada_para'] ?? null,
            (int)($data['duracion_minutos'] ?? 30)
        );

        return response()->json(['message'=>'Sesión de atención habilitada.','data'=>$session], 201);
    }

    public function approve(Request $request, AtencionSesion $sesion): JsonResponse
    {
        abort_unless($sesion->estado === 'solicitada', 422, 'Esta solicitud ya fue revisada.');
        $data = $request->validate([
            'programada_para' => ['nullable','date'],
            'duracion_minutos' => ['nullable','integer','min:15','max:180'],
            'nota_admin' => ['nullable','string','max:1000'],
        ]);

        $start = isset($data['programada_para']) && $data['programada_para']
            ? Carbon::parse($data['programada_para'])
            : now();
        $minutes = (int)($data['duracion_minutos'] ?? 30);

        $sesion->update([
            'estado'=>'aprobada',
            'aprobada_por_usuario_id'=>$request->user()->id,
            'aprobada_at'=>now(),
            'programada_para'=>$start,
            'habilitada_desde'=>$start->copy()->subMinutes(5),
            'habilitada_hasta'=>$start->copy()->addMinutes($minutes),
            'nota_admin'=>$data['nota_admin'] ?? null,
        ]);

        Audit::log($request, 'atencion_aprobada', $sesion, 'Se aprobó una sesión de atención.');
        $this->pushClient($sesion->fresh(), 'Atención VITI aprobada', 'Tu sesión fue aprobada para '.$start->format('d/m/Y H:i').'.');

        return response()->json(['message'=>'Solicitud aprobada.','data'=>$sesion->fresh()]);
    }

    public function reject(Request $request, AtencionSesion $sesion): JsonResponse
    {
        abort_unless($sesion->estado === 'solicitada', 422, 'Esta solicitud ya fue revisada.');
        $data = $request->validate(['nota_admin'=>['nullable','string','max:1000']]);
        $sesion->update([
            'estado'=>'rechazada',
            'aprobada_por_usuario_id'=>$request->user()->id,
            'nota_admin'=>$data['nota_admin'] ?? null,
        ]);
        $this->pushClient($sesion->fresh(), 'Solicitud de atención revisada', 'Tu solicitud no fue habilitada. Puedes escribirnos en el chat para coordinar otra opción.');
        return response()->json(['message'=>'Solicitud rechazada.','data'=>$sesion->fresh()]);
    }

    public function finish(Request $request, AtencionSesion $sesion): JsonResponse
    {
        abort_unless(in_array($sesion->estado, ['aprobada'], true), 422, 'La sesión ya no está activa.');
        $sesion->update(['estado'=>'finalizada','habilitada_hasta'=>now()]);
        return response()->json(['message'=>'Sesión finalizada.','data'=>$sesion->fresh()]);
    }

    private function createApproved(Request $request, Conversacion $conversation, string $modality, string $reason, ?string $scheduled, int $minutes): AtencionSesion
    {
        $start = $scheduled ? Carbon::parse($scheduled) : now();
        $session = AtencionSesion::create([
            'conversacion_id'=>$conversation->id,
            'cliente_id'=>$conversation->cliente_id,
            'solicitada_por_usuario_id'=>$request->user()->id,
            'aprobada_por_usuario_id'=>$request->user()->id,
            'modalidad'=>$modality,
            'estado'=>'aprobada',
            'motivo'=>$reason,
            'programada_para'=>$start,
            'habilitada_desde'=>$start->copy()->subMinutes(5),
            'habilitada_hasta'=>$start->copy()->addMinutes($minutes),
            'aprobada_at'=>now(),
        ]);
        $this->pushClient($session, 'Atención VITI habilitada', 'El equipo VITI habilitó una sesión de atención para '.$start->format('d/m/Y H:i').'.');
        return $session;
    }

    private function pushClient(AtencionSesion $session, string $title, string $body): void
    {
        $targetIds = Usuario::where('estado','activo')
            ->where('rol','cliente')
            ->where('cliente_id',$session->cliente_id)
            ->pluck('id')->all();
        FirebasePush::sendToUsers($targetIds, $title, $body, [
            'type'=>'atencion',
            'conversation_id'=>$session->conversacion_id,
            'session_id'=>$session->id,
            'path'=>'/mi-buzon?c='.$session->conversacion_id,
        ]);
    }

    private function primaryConversation(int $clientId): Conversacion
    {
        $conversation = Conversacion::query()
            ->where('cliente_id',$clientId)
            ->where('canal_principal',true)
            ->first();
        abort_unless($conversation, 404, 'Tu canal de Atención VITI todavía no está disponible.');
        return $conversation;
    }
}
