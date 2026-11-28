<?php
namespace App\Http\Controllers\Sensaciones;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class DirectUploadController extends Controller {
    private const TYPES=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','video/mp4'=>'mp4','video/webm'=>'webm'];
    private function disk() {
        abort_unless(config('sensaciones.enabled') && config('sensaciones.direct_uploads'),404);
        abort_unless(config('filesystems.disks.private_uploads.driver')==='s3',503,'Se requiere R2 privado.');
        return Storage::disk('private_uploads');
    }
    public function begin(Request $request) {
        $disk=$this->disk();
        $d=$request->validate(['title'=>'required|string|max:150','description'=>'nullable|string|max:2000',
          'mime'=>'required|in:'.implode(',',array_keys(self::TYPES)),'bytes'=>'required|integer|min:1|max:262144000']);
        abort_if(str_starts_with($d['mime'],'image/') && $d['bytes']>15728640,422,'Máximo 15 MiB para fotos.');
        $id=(string)Str::uuid(); $expires=now()->addMinutes(15);
        $key='sensaciones/staging/'.$id;
        $client=$disk->getClient();
        $command=$client->getCommand('PutObject',[
            'Bucket'=>config('filesystems.disks.private_uploads.bucket'),'Key'=>$key,
            'ContentType'=>$d['mime'],'ContentLength'=>(int)$d['bytes'],
        ]);
        $signed=$client->createPresignedRequest($command,$expires);
        DB::table('sensaciones_uploads')->insert([
          'id'=>$id,'usuario_id'=>$request->user()->id,'title'=>$d['title'],'description'=>$d['description']??null,
          'mime'=>$d['mime'],'bytes'=>$d['bytes'],'expires_at'=>$expires,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['id'=>$id,'url'=>(string)$signed->getUri(),'headers'=>['Content-Type'=>$d['mime']],'expires_at'=>$expires->toIso8601String()],201);
    }
    public function finish(Request $request, string $id) {
        $disk=$this->disk();
        return DB::transaction(function() use($request,$id,$disk){
            $row=DB::table('sensaciones_uploads')->where('id',$id)->where('usuario_id',$request->user()->id)->lockForUpdate()->first();
            abort_unless($row,404);
            if($row->media_id) return response()->json(['id'=>$row->media_id]);
            abort_if(Carbon::parse($row->expires_at)->isPast(),422,'La sesión de carga ha vencido.');
            $source='sensaciones/staging/'.$id;
            abort_unless($disk->exists($source) && $disk->size($source)===(int)$row->bytes,422,'El tamaño del archivo no coincide.');
            // La URL de carga nunca permite escribir en la ubicación definitiva.
            $target='sensaciones/media/'.Str::uuid().'.'.self::TYPES[$row->mime];
            abort_unless($disk->copy($source,$target),503,'No se pudo finalizar la carga.');
            try {
                abort_unless($disk->size($target)===(int)$row->bytes,422,'Tamaño final incorrecto.');
                $head=$disk->getClient()->getObject(['Bucket'=>config('filesystems.disks.private_uploads.bucket'),'Key'=>$target,'Range'=>'bytes=0-4095']);
                $bytes=(string)$head['Body'];
                $ok=match($row->mime){
                    'image/jpeg'=>str_starts_with($bytes,"\xff\xd8\xff"),
                    'image/png'=>str_starts_with($bytes,"\x89PNG\r\n\x1a\n"),
                    'image/webp'=>substr($bytes,0,4)==='RIFF' && substr($bytes,8,4)==='WEBP',
                    'video/mp4'=>substr($bytes,4,4)==='ftyp',
                    'video/webm'=>str_starts_with($bytes,"\x1a\x45\xdf\xa3") && str_contains($bytes,'webm'),
                    default=>false,
                };
                abort_unless($ok,422,'El encabezado del archivo no coincide con el formato declarado.');
                $media=DB::table('sensaciones_media')->insertGetId([
                    'usuario_id'=>$row->usuario_id,'title'=>$row->title,'description'=>$row->description,
                    'kind'=>str_starts_with($row->mime,'image/')?'photo':'video',
                    'mime'=>$row->mime,'path'=>$target,'published'=>false,'created_at'=>now(),'updated_at'=>now()]);
                DB::table('sensaciones_uploads')->where('id',$id)->update(['media_id'=>$media,'updated_at'=>now()]);
            } catch(\Throwable $e) {
                try{$disk->delete($target);}catch(\Throwable $cleanup){report($cleanup);}
                throw $e;
            }
            return response()->json(['id'=>$media],201);
        });
    }
}
