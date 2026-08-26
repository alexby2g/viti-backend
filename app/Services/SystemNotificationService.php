<?php
namespace App\Services;
use App\Models\{AlertaSaas,Usuario};
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
class SystemNotificationService
{
    public const CHANNEL='notification';
    public const CATEGORIES=['system','request','payment','subscription','service','support'];
    public function ensure(Usuario|int $recipient,string $key,string $category,string $type,string $title,?string $message=null,?string $path=null,?int $businessId=null,?int $appId=null,?string $resourceType=null,?int $resourceId=null,array $data=[]):AlertaSaas
    {
        $category=Str::lower(trim($category));
        if(!in_array($category,self::CATEGORIES,true))throw new InvalidArgumentException('Categoría de notificación VITI no válida: '.$category);
        $userId=$recipient instanceof Usuario?(int)$recipient->id:(int)$recipient;
        return AlertaSaas::firstOrCreate(['usuario_id'=>$userId,'clave'=>$key],[
            'empresa_id'=>$businessId,'aplicacion_id'=>$appId,'canal'=>self::CHANNEL,'categoria'=>$category,'tipo'=>Str::lower(trim($type?:'info')),
            'titulo'=>$title,'mensaje'=>$message,'ruta'=>$path,'recurso_tipo'=>$resourceType,'recurso_id'=>$resourceId,
            'data'=>Arr::except($data,['password','token','api_key','secret']),
        ]);
    }
    public function forPlatformAdmins(string $key,string $category,string $type,string $title,?string $message=null,?string $path=null,?int $businessId=null,?int $appId=null,?string $resourceType=null,?int $resourceId=null,array $data=[],?int $exceptUserId=null):array
    {
        $admins=Usuario::query()->where('estado','activo')->whereIn('rol',['superadmin','administrador'])->when($exceptUserId,fn($q)=>$q->whereKeyNot($exceptUserId))->get(['id']);
        foreach($admins as $admin)$this->ensure($admin,$key,$category,$type,$title,$message,$path,$businessId,$appId,$resourceType,$resourceId,$data);
        return $admins->pluck('id')->map(fn($id)=>(int)$id)->all();
    }
}
