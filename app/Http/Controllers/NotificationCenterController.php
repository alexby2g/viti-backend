<?php

namespace App\Http\Controllers;

use App\Models\{AlertaSaas,Mensaje};
use App\Services\SaasAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationCenterController extends Controller
{
    public function index(Request $request, SaasAlertService $alerts): JsonResponse
    {
        $user = $request->user();
        $alerts->syncFor($user);

        $messages = Mensaje::query()
            ->whereNull('leido_at')
            ->whereHas('conversacion',fn($q)=>$q->where('canal_principal',true))
            ->with(['usuario:id,nombre,apellido,rol','conversacion:id,cliente_id,asunto','conversacion.cliente:id,nombre']);

        if ($user->rol === 'cliente') {
            $messages->whereHas('conversacion',fn($q)=>$q->where('cliente_id',(int)$user->cliente_id))
                ->whereHas('usuario',fn($q)=>$q->where('rol','!=','cliente'));
        } else {
            $messages->whereHas('usuario',fn($q)=>$q->where('rol','cliente'));
        }

        $messageItems = $messages->latest('created_at')->limit(10)->get()->map(function (Mensaje $m) use ($user): array {
            $isClient = $user->rol === 'cliente';
            $preview = trim((string)$m->mensaje);
            if ($preview === '' && $m->archivo_path) $preview = 'Imagen adjunta';
            return [
                'id'=>'m-'.$m->id,'tipo'=>'mensaje','conversacion_id'=>$m->conversacion_id,
                'titulo'=>$isClient ? 'Nueva respuesta de Atención VITI' : 'Nuevo mensaje de '.($m->conversacion?->cliente?->nombre ?: 'cliente'),
                'asunto'=>'Atención VITI','mensaje'=>str($preview)->limit(95)->toString(),
                'path'=>$isClient ? '/mi-buzon?c='.$m->conversacion_id : '/buzon?c='.$m->conversacion_id,
                'created_at'=>$m->created_at,
            ];
        });

        $saasItems = AlertaSaas::where('usuario_id',$user->id)->whereNull('leida_at')->latest()->limit(10)->get()->map(fn(AlertaSaas $a) => [
            'id'=>'s-'.$a->id,'tipo'=>$a->tipo,'titulo'=>$a->titulo,'asunto'=>'VITI SaaS','mensaje'=>$a->mensaje,
            'path'=>$a->ruta,'created_at'=>$a->created_at,
        ]);

        $items = $messageItems->concat($saasItems)->sortByDesc('created_at')->take(10)->values();
        $count = (clone $messages)->count() + AlertaSaas::where('usuario_id',$user->id)->whereNull('leida_at')->count();
        return response()->json(['data'=>['no_leidos'=>$count,'items'=>$items]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $messages = Mensaje::query()->whereNull('leido_at')->whereHas('conversacion',fn($q)=>$q->where('canal_principal',true));
        if ($user->rol === 'cliente') {
            $messages->whereHas('conversacion',fn($q)=>$q->where('cliente_id',(int)$user->cliente_id))
                ->whereHas('usuario',fn($q)=>$q->where('rol','!=','cliente'));
        } else {
            $messages->whereHas('usuario',fn($q)=>$q->where('rol','cliente'));
        }
        $m = $messages->update(['leido_at'=>now()]);
        $a = AlertaSaas::where('usuario_id',$user->id)->whereNull('leida_at')->update(['leida_at'=>now()]);
        return response()->json(['message'=>'Notificaciones marcadas como leídas.','data'=>['actualizados'=>$m+$a]]);
    }
}
