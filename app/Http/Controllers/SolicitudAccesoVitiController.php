<?php

namespace App\Http\Controllers;

use App\Models\{InvitacionCliente, PlanViti, SolicitudAccesoViti};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SolicitudAccesoVitiController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required','string','min:3','max:180'],
            'telefono' => ['required','string','regex:/^[0-9]{7,15}$/'],
            'whatsapp' => ['nullable','string','regex:/^[0-9]{7,15}$/'],
            'negocio' => ['required','string','min:2','max:180'],
            'actividad' => ['nullable','string','max:180'],
            'plan_codigo' => ['nullable','string','max:60'],
            'modalidad' => ['nullable','in:mensual,anual'],
            'mensaje' => ['nullable','string','max:2500'],
        ]);

        if (!empty($data['plan_codigo'])) {
            abort_unless(PlanViti::query()->where('codigo',$data['plan_codigo'])->where('activo',true)->exists(),422,'El plan seleccionado ya no está disponible.');
        }

        $recent = SolicitudAccesoViti::query()
            ->where('telefono',$data['telefono'])
            ->where('created_at','>=',now()->subDay())
            ->whereIn('estado',['pendiente','en_revision'])
            ->exists();

        abort_if($recent,422,'Ya existe una solicitud de acceso reciente para este teléfono. AGR Studio la revisará antes de crear un nuevo acceso.');

        $access = SolicitudAccesoViti::create([...$data,'estado'=>'pendiente']);

        return response()->json([
            'message'=>'Solicitud recibida. AGR Studio revisará tus datos y, si corresponde, te enviará un acceso personal a VITI.',
            'data'=>[
                'id'=>$access->id,
                'estado'=>$access->estado,
                'plan_codigo'=>$access->plan_codigo,
                'modalidad'=>$access->modalidad,
            ],
        ],201);
    }

    public function resolveCode(string $codigo): JsonResponse
    {
        $code = Str::upper(trim($codigo));
        $invitation = InvitacionCliente::query()->where('codigo',$code)->first();

        abort_unless($invitation,404,'El código de acceso no existe.');
        abort_unless($invitation->estado === 'pendiente',410,'Este código ya fue utilizado o deshabilitado.');
        abort_if($invitation->expira_at && $invitation->expira_at->isPast(),410,'Este código de acceso ya venció.');

        return response()->json(['data'=>[
            'codigo'=>$invitation->codigo,
            'token'=>$invitation->token,
            'expira_at'=>$invitation->expira_at,
            'ruta'=>'/registro-cliente/'.$invitation->token,
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = SolicitudAccesoViti::query()
            ->with(['revisor:id,nombre,apellido,usuario','plan:id,codigo,nombre,precio_mensual,precio_anual','invitacion:id,codigo,estado,expira_at,usada_at'])
            ->latest('id');

        if ($request->filled('estado')) $query->where('estado',$request->input('estado'));

        return response()->json(['data'=>$query->paginate(min(max((int)$request->input('per_page',30),1),100))]);
    }

    public function markReview(Request $request, SolicitudAccesoViti $solicitud): JsonResponse
    {
        abort_if(in_array($solicitud->estado,['aprobada','rechazada'],true),422,'Esta solicitud ya fue cerrada.');
        $solicitud->update(['estado'=>'en_revision','revisado_por'=>$request->user()?->id,'revisado_at'=>now()]);
        return response()->json(['message'=>'La solicitud quedó marcada como en revisión.','data'=>$solicitud->fresh(['revisor','plan','invitacion'])]);
    }

    public function approve(Request $request, SolicitudAccesoViti $solicitud): JsonResponse
    {
        abort_if(in_array($solicitud->estado,['aprobada','rechazada'],true),422,'Esta solicitud ya fue cerrada.');
        abort_if($solicitud->invitacion_id,422,'Esta solicitud ya tiene un acceso generado.');
        $data=$request->validate(['dias_vigencia'=>['nullable','integer','min:1','max:30'],'notas'=>['nullable','string','max:2000']]);

        [$access,$invitation]=DB::transaction(function() use($request,$solicitud,$data):array{
            $locked=SolicitudAccesoViti::query()->lockForUpdate()->findOrFail($solicitud->id);
            abort_if(in_array($locked->estado,['aprobada','rechazada'],true),422,'Esta solicitud ya fue cerrada.');
            abort_if($locked->invitacion_id,422,'Esta solicitud ya tiene un acceso generado.');
            $days=(int)($data['dias_vigencia']??7);
            $invitation=InvitacionCliente::create([
                'token'=>Str::random(64),
                'codigo'=>$this->newCode(),
                'creada_por'=>$request->user()?->id,
                'estado'=>'pendiente',
                'expira_at'=>now()->addDays($days),
            ]);
            $locked->update(['estado'=>'aprobada','revisado_por'=>$request->user()?->id,'revisado_at'=>now(),'notas'=>$data['notas']??$locked->notas,'invitacion_id'=>$invitation->id]);
            return [$locked->fresh(['revisor','plan','invitacion']),$invitation];
        });

        return response()->json(['message'=>'Solicitud aprobada. Comparte el código y el enlace personal con el solicitante.','data'=>[
            'solicitud'=>$access,'codigo'=>$invitation->codigo,'expira_at'=>$invitation->expira_at,'ruta'=>'/registro-cliente/'.$invitation->token,
        ]]);
    }

    public function reject(Request $request, SolicitudAccesoViti $solicitud): JsonResponse
    {
        abort_if(in_array($solicitud->estado,['aprobada','rechazada'],true),422,'Esta solicitud ya fue cerrada.');
        $data=$request->validate(['notas'=>['required','string','min:5','max:2000']]);
        $solicitud->update(['estado'=>'rechazada','revisado_por'=>$request->user()?->id,'revisado_at'=>now(),'notas'=>$data['notas']]);
        return response()->json(['message'=>'Solicitud de acceso rechazada.','data'=>$solicitud->fresh(['revisor','plan','invitacion'])]);
    }

    private function newCode(): string
    {
        do { $code='VITI-'.Str::upper(Str::random(6)); }
        while (InvitacionCliente::query()->where('codigo',$code)->exists());
        return $code;
    }
}
