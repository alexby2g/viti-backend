<?php

namespace App\Http\Controllers;

use App\Models\{AtencionSesion,Conversacion,Usuario};
use App\Services\ChatChannelService;
use App\Support\{Audit,FirebasePush};
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtencionSesionController extends Controller
{
    public function __construct(private ChatChannelService $channels) {}

    public function clientStatus(Request $request): JsonResponse
    {
        $conversation = $this->channels->clientChannel($request);

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

        $conversation = $this->channels->clientChannel($request);
        $clientId = $conversation->contexto===ChatChannelService::ELECTROFRIO ? null : (int)$request->user()->cliente_id;
        $electroClientId = $conversation->contexto===ChatChannelService::ELECTROFRIO ? (int)$request->user()->electrofrio_cliente_id : null;

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
            'electrofrio_cliente_id' => $electroClientId,
            'solicitada_por_usuario_id' => $request->user()->id,
            'modalidad' => $data['modalidad'],
            'estado' => 'solicitada',
            'motivo' => trim($data['motivo']),
        ]);

        Audit::log($request, 'atencion_solicitada', $session, 'El cliente solicitó una sesión de atención.');

        $targetIds = $conversation->contexto===ChatChannelService::ELECTROFRIO
            ? $this->channels->businessUserIds($conversation)
            : ($conversation->responsable_usuario_id ? [$conversation->responsable_usuario_id] : Usuario::where('estado','activo')->where('rol','!=','cliente')->pluck('id')->all());

        FirebasePush::sendToUsers(
            $targetIds,
            'Nueva solicitud de atención',
            ($request->user()->nombre ?: 'Un cliente').' solicita '.($data['modalidad'] === 'pantalla' ? 'asistencia con pantalla' : ($data['modalidad'] === 'video' ? 'videollamada' : 'llamada de voz')).'.',
            ['type'=>'atencion','contexto'=>$conversation->contexto,'conversation_id'=>$conversation->id,'session_id'=>$session->id,'path'=>($conversation->contexto===ChatChannelService::ELECTROFRIO?$this->channels->businessPath($conversation):$this->channels->adminPath($conversation)).'?c='.$conversation->id]
        );

        return response()->json(['message'=>'Solicitud enviada. El equipo responsable la revisará antes de habilitar la llamada.','data'=>$session], 201);
    }

    public function clientCancel(Request $request, AtencionSesion $sesion): JsonResponse
    {
        $sesion->loadMissing('conversacion');
        $this->channels->assertClient($request, $sesion->conversacion, $this->channels->context($request));
        if($sesion->conversacion->contexto===ChatChannelService::ELECTROFRIO) abort_unless((int)$sesion->electrofrio_cliente_id===(int)$request->user()->electrofrio_cliente_id,403,'No tienes permiso para modificar esta solicitud.');
        else abort_unless((int)$sesion->cliente_id === (int)$request->user()->cliente_id, 403, 'No tienes permiso para modificar esta solicitud.');
        abort_unless(in_array($sesion->estado, ['solicitada','aprobada'], true), 422, 'Esta sesión ya no puede cancelarse.');
        $sesion->update(['estado'=>'cancelada']);
        return response()->json(['message'=>'Solicitud cancelada.','data'=>$sesion->fresh()]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $context=$this->channels->context($request);
        $query = AtencionSesion::query()
            ->with(['cliente:id,nombre,telefono,foto_path','electrofrioCliente:id,nombre,telefono','solicitante:id,nombre,apellido,rol','aprobador:id,nombre,apellido,rol'])
            ->whereHas('conversacion', function($conversation)use($request,$context):void{
                $conversation->where('contexto',$context)->where('canal_principal',true);
                if($context===ChatChannelService::ELECTROFRIO){$empresa=$this->channels->ensureBusinessChannels($request);$conversation->where('empresa_id',$empresa->id);}
            })
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
        $this->assertOperator($request,$conversation);
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
        $sesion->loadMissing('conversacion');
        $this->assertOperator($request,$sesion->conversacion);
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
        $this->pushClient($sesion->fresh(), $this->channels->label($sesion->conversacion->contexto).' · atención aprobada', 'Tu sesión fue aprobada para '.$start->format('d/m/Y H:i').'.');

        return response()->json(['message'=>'Solicitud aprobada.','data'=>$sesion->fresh()]);
    }

    public function reject(Request $request, AtencionSesion $sesion): JsonResponse
    {
        $sesion->loadMissing('conversacion');
        $this->assertOperator($request,$sesion->conversacion);
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
        $sesion->loadMissing('conversacion');
        $this->assertOperator($request,$sesion->conversacion);
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
            'electrofrio_cliente_id'=>$conversation->electrofrio_cliente_id,
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
        $this->pushClient($session, $this->channels->label($conversation->contexto).' · atención habilitada', 'Se habilitó una sesión de atención para '.$start->format('d/m/Y H:i').'.');
        return $session;
    }

    private function pushClient(AtencionSesion $session, string $title, string $body): void
    {
        $session->loadMissing('conversacion');
        $targetIds = $session->conversacion?->contexto===ChatChannelService::ELECTROFRIO
            ? Usuario::where('estado','activo')->where('rol','cliente_negocio')->where('electrofrio_cliente_id',$session->electrofrio_cliente_id)->pluck('id')->all()
            : Usuario::where('estado','activo')->where('rol','cliente')->where('cliente_id',$session->cliente_id)->pluck('id')->all();
        FirebasePush::sendToUsers($targetIds, $title, $body, [
            'type'=>'atencion',
            'conversation_id'=>$session->conversacion_id,
            'session_id'=>$session->id,
            'contexto'=>$session->conversacion?->contexto,
            'path'=>$this->channels->clientPath($session->conversacion).'?c='.$session->conversacion_id,
        ]);
    }

    private function assertOperator(Request $request,Conversacion $conversation):void
    {
        if($this->channels->context($request)===ChatChannelService::ELECTROFRIO)$this->channels->assertBusiness($request,$conversation);
        else $this->channels->assertContext($conversation,ChatChannelService::VITI);
    }
}
