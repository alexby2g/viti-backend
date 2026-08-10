<?php

namespace App\Services;

use App\Models\{Aplicacion,Cliente,Conversacion,ElectrofrioCliente,Empresa,Usuario};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ChatChannelService
{
    public const VITI = 'viti';
    public const ELECTROFRIO = 'electrofrio';

    public function context(Request $request): string
    {
        $context=(string)($request->route('chat_context') ?: self::VITI);
        abort_unless(in_array($context,[self::VITI,self::ELECTROFRIO],true),404,'El buzón solicitado no existe.');
        return $context;
    }

    public function clientChannel(Request $request, ?string $context=null): Conversacion
    {
        $context??=$this->context($request);
        if ($context===self::ELECTROFRIO) return $this->customerChannel($request);
        $clientId=(int)$request->user()->cliente_id;
        abort_unless($clientId,403,'Tu cuenta no tiene un perfil de cliente VITI.');
        return $this->ensureViti($clientId);
    }

    public function customerChannel(Request $request): Conversacion
    {
        $customer=$request->user()->electrofrioCliente?->loadMissing('empresa');
        abort_unless($customer?->activo,403,'Tu acceso a Electrofrío no está activo.');
        $app=$this->electrofrioApp((int)$customer->empresa_id);
        return $this->ensureElectrofrio($customer,$app);
    }

    public function ensureAdminChannels(string $context): void
    {
        if ($context!==self::VITI) return;
        Cliente::query()->select('id')->orderBy('id')->chunkById(100,function($clients):void{
            foreach($clients as $client)$this->ensureViti((int)$client->id);
        });
    }

    public function ensureBusinessChannels(Request $request): Empresa
    {
        $empresa=app(TenantContext::class)->resolve($request);
        app(TenantContext::class)->assertModule($empresa,'buzon');
        app(TenantContext::class)->assertCanUse($request->user(),$empresa,'buzon');
        $this->electrofrioApp((int)$empresa->id);
        return $empresa;
    }

    public function adminQuery(string $context): Builder
    {
        return Conversacion::query()->where('contexto',$context)->where('canal_principal',true);
    }

    public function businessQuery(Request $request): Builder
    {
        $empresa=$this->ensureBusinessChannels($request);
        return Conversacion::query()->where('contexto',self::ELECTROFRIO)->where('empresa_id',$empresa->id)->whereNotNull('electrofrio_cliente_id')->where('canal_principal',true);
    }

    public function assertContext(Conversacion $conversation,string $context):void
    {
        abort_unless($conversation->contexto===$context && $conversation->canal_principal,404,'Esta conversación no pertenece a este buzón.');
    }

    public function assertClient(Request $request,Conversacion $conversation,string $context):void
    {
        if($context===self::ELECTROFRIO){$this->assertCustomer($request,$conversation);return;}
        $this->assertContext($conversation,self::VITI);
        abort_unless((int)$conversation->cliente_id===(int)$request->user()->cliente_id,403,'No tienes permiso para acceder a esta conversación.');
    }

    public function assertCustomer(Request $request,Conversacion $conversation):void
    {
        $this->assertContext($conversation,self::ELECTROFRIO);
        abort_unless($request->user()->rol==='cliente_negocio' && (int)$conversation->electrofrio_cliente_id===(int)$request->user()->electrofrio_cliente_id,403,'Esta conversación pertenece a otro cliente.');
    }

    public function assertBusiness(Request $request,Conversacion $conversation):Empresa
    {
        $this->assertContext($conversation,self::ELECTROFRIO);
        $empresa=app(TenantContext::class)->resolve($request);
        app(TenantContext::class)->assertCanUse($request->user(),$empresa,'buzon');
        abort_unless((int)$conversation->empresa_id===(int)$empresa->id,403,'Esta conversación pertenece a otro negocio.');
        return $empresa;
    }

    public function label(string $context):string{return $context===self::ELECTROFRIO?'Electrofrío':'Atención VITI';}
    public function adminPath(Conversacion $conversation):string{return $conversation->contexto===self::ELECTROFRIO?'/apps/electrofrio/buzon':'/buzon';}
    public function businessPath(Conversacion $conversation):string{return '/mi-apps/electrofrio/buzon';}
    public function clientPath(Conversacion $conversation):string{return $conversation->contexto===self::ELECTROFRIO?'/portal/electrofrio/mensajes':'/mi-buzon';}

    public function businessUserIds(Conversacion $conversation):array
    {
        $empresa=Empresa::query()->with('planViti')->find($conversation->empresa_id);
        if(!$empresa || !app(TenantContext::class)->hasModule($empresa,'buzon'))return [];
        return $empresa->usuarios()->where('usuarios.estado','activo')->wherePivot('activo',true)->get()
            ->filter(function(Usuario $user):bool{
                if(in_array($user->pivot?->rol_negocio,['propietario','administrador'],true))return true;
                $permissions=$user->pivot?->permisos;
                if(is_string($permissions))$permissions=json_decode($permissions,true);
                return is_array($permissions)&&in_array('buzon',$permissions,true);
            })->pluck('id')->all();
    }

    private function electrofrioApp(int $businessId):Aplicacion
    {
        $app=Aplicacion::query()->where('empresa_id',$businessId)->where('estado','activo')->where('acceso_cliente',true)
            ->whereHas('catalogo',fn(Builder $q)=>$q->where('clave',self::ELECTROFRIO))->latest('id')->first();
        abort_unless($app,404,'Este negocio no tiene un buzón de Electrofrío activo.');
        return $app;
    }

    private function ensureViti(int $clientId):Conversacion
    {
        $channel=Conversacion::query()->where('cliente_id',$clientId)->where('contexto',self::VITI)->where('canal_principal',true)->first();
        if($channel)return $channel;
        return Conversacion::create(['cliente_id'=>$clientId,'responsable_usuario_id'=>$this->platformAdminId(),'asunto'=>$this->label(self::VITI),'estado'=>'abierta','contexto'=>self::VITI,'canal_principal'=>true]);
    }

    private function ensureElectrofrio(ElectrofrioCliente $customer,Aplicacion $app):Conversacion
    {
        $query=Conversacion::query()->where('electrofrio_cliente_id',$customer->id)->where('empresa_id',$customer->empresa_id)->where('aplicacion_id',$app->id)->where('contexto',self::ELECTROFRIO)->where('canal_principal',true);
        if($channel=$query->first())return $channel;
        Conversacion::query()->where('electrofrio_cliente_id',$customer->id)->where('contexto',self::ELECTROFRIO)->update(['canal_principal'=>false]);
        $responsible=Usuario::query()->where('estado','activo')->whereHas('negocios',fn($q)=>$q->where('empresas.id',$customer->empresa_id)->where('empresa_usuario.activo',true)->whereIn('empresa_usuario.rol_negocio',['propietario','administrador']))->orderBy('id')->value('id');
        return Conversacion::create(['cliente_id'=>null,'electrofrio_cliente_id'=>$customer->id,'empresa_id'=>$customer->empresa_id,'aplicacion_id'=>$app->id,'responsable_usuario_id'=>$responsible,'asunto'=>'Atención de '.$customer->nombre,'estado'=>'abierta','contexto'=>self::ELECTROFRIO,'canal_principal'=>true]);
    }

    private function platformAdminId():?int{return Usuario::query()->where('estado','activo')->where('rol','superadmin')->orderBy('id')->value('id');}
}
