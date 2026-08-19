<?php
namespace App\Http\Controllers;
use App\Models\{AlertaSaas,ProyectoPago,SolicitudSistema,SuscripcionPago};
use App\Services\UnreadInboxService;
use Illuminate\Http\{JsonResponse,Request};
class AttentionCountersController extends Controller
{
    public function __invoke(Request $request,UnreadInboxService $inbox):JsonResponse
    {
        $user=$request->user();
        $data=[
            'notificaciones_no_leidas'=>AlertaSaas::query()->where('usuario_id',$user->id)->where('canal','notification')->whereNull('leida_at')->count(),
            'mensajes_no_leidos'=>$inbox->countFor($user),'mensajes_por_contexto'=>$inbox->countsByContext($user),'solicitudes_pendientes'=>0,'pagos_pendientes'=>0,
        ];
        if($user->isPlatformAdmin())$data['solicitudes_pendientes']=SolicitudSistema::query()->whereIn('estado',['borrador','en_revision'])->count();
        if($user->isSuperAdmin())$data['pagos_pendientes']=ProyectoPago::query()->where('estado_revision','pendiente_revision')->count()+SuscripcionPago::query()->where('estado_revision','pendiente_revision')->count();
        return response()->json(['data'=>$data]);
    }
}
