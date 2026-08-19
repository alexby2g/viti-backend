<?php
namespace App\Services;
use App\Models\{Mensaje,Usuario};
use Illuminate\Database\Eloquent\Builder;
class UnreadInboxService
{
    public function queryFor(Usuario $user): Builder
    {
        $query=Mensaje::query()->whereNull('leido_at')->whereNull('eliminado_at')->whereHas('conversacion',fn(Builder $q)=>$q->where('canal_principal',true));
        if($user->rol==='cliente_negocio'){
            $query->whereHas('conversacion',fn(Builder $q)=>$q->where('contexto',ChatChannelService::ELECTROFRIO)->where('electrofrio_cliente_id',$user->electrofrio_cliente_id))->where('usuario_id','!=',$user->id);
        }elseif($user->rol==='cliente'){
            $businessIds=$user->negocios()->wherePivot('activo',true)->pluck('empresas.id');
            $query->where(function(Builder $outer)use($user,$businessIds):void{
                $outer->where(function(Builder $viti)use($user):void{$viti->whereHas('conversacion',fn(Builder $q)=>$q->where('contexto',ChatChannelService::VITI)->where('cliente_id',(int)$user->cliente_id))->whereHas('usuario',fn(Builder $q)=>$q->where('rol','!=','cliente'));})
                    ->orWhere(function(Builder $electro)use($businessIds):void{$electro->whereHas('conversacion',fn(Builder $q)=>$q->where('contexto',ChatChannelService::ELECTROFRIO)->whereIn('empresa_id',$businessIds))->whereHas('usuario',fn(Builder $q)=>$q->where('rol','cliente_negocio'));});
            });
        }elseif($user->isSuperAdmin()){
            $query->whereHas('conversacion',fn(Builder $q)=>$q->where('contexto',ChatChannelService::VITI))->whereHas('usuario',fn(Builder $q)=>$q->where('rol','cliente'));
        }else{
            $query->whereRaw('1 = 0');
        }
        return $query;
    }
    public function countFor(Usuario $user):int{return $this->queryFor($user)->count();}
    public function countsByContext(Usuario $user):array
    {
        $rows=$this->queryFor($user)->join('conversaciones','conversaciones.id','=','mensajes.conversacion_id')
            ->selectRaw("COALESCE(conversaciones.contexto, ?) AS contexto, COUNT(*) AS total",[ChatChannelService::VITI])
            ->groupBy('conversaciones.contexto')->pluck('total','contexto');
        return [ChatChannelService::VITI=>(int)($rows[ChatChannelService::VITI]??0),ChatChannelService::ELECTROFRIO=>(int)($rows[ChatChannelService::ELECTROFRIO]??0)];
    }
}
