<?php
namespace App\Http\Controllers;
use App\Models\AlertaSaas;
use App\Services\SaasAlertService;
use Illuminate\Http\{JsonResponse,Request};
class NotificationCenterController extends Controller
{
    public function index(Request $request,SaasAlertService $alerts):JsonResponse
    {
        $user=$request->user();$alerts->syncFor($user);
        $query=AlertaSaas::query()->where('usuario_id',$user->id)->where('canal','notification');
        $count=(clone $query)->whereNull('leida_at')->count();
        $items=$query->latest('created_at')->latest('id')->limit(20)->get()->map(fn(AlertaSaas $a)=>$this->present($a))->values();
        return response()->json(['data'=>['no_leidos'=>$count,'items'=>$items]]);
    }
    public function markRead(Request $request,AlertaSaas $alerta):JsonResponse
    {
        abort_unless((int)$alerta->usuario_id===(int)$request->user()->id,404);abort_unless($alerta->canal==='notification',404);
        if(!$alerta->leida_at)$alerta->update(['leida_at'=>now()]);
        return response()->json(['message'=>'Notificación marcada como leída.','data'=>$this->present($alerta->fresh())]);
    }
    public function markAllRead(Request $request):JsonResponse
    {
        $updated=AlertaSaas::query()->where('usuario_id',$request->user()->id)->where('canal','notification')->whereNull('leida_at')->update(['leida_at'=>now()]);
        return response()->json(['message'=>$updated?'Notificaciones marcadas como leídas.':'No había notificaciones pendientes.','data'=>['actualizados'=>$updated]]);
    }
    private function present(AlertaSaas $a):array{return ['id'=>$a->id,'canal'=>$a->canal,'categoria'=>$a->categoria,'tipo'=>$a->tipo,'titulo'=>$a->titulo,'descripcion'=>$a->mensaje,'mensaje'=>$a->mensaje,'path'=>$a->ruta,'recurso_tipo'=>$a->recurso_tipo,'recurso_id'=>$a->recurso_id,'data'=>$a->data?:[],'leida'=>(bool)$a->leida_at,'leida_at'=>$a->leida_at,'created_at'=>$a->created_at];}
}
