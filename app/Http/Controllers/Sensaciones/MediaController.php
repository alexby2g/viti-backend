<?php

namespace App\Http\Controllers\Sensaciones;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaController extends Controller
{
    private function enabled(): void {
        abort_unless(config('sensaciones.enabled'), 404);
    }

    private function item($row): array {
        return [
            'id' => $row->id, 'title' => $row->title,
            'description' => $row->description, 'kind' => $row->kind,
            'published' => (bool) $row->published,
            'url' => '/api/v1/sensaciones/media/'.$row->id.'/file',
            'poster_url' => !empty($row->poster_path) ? '/api/v1/sensaciones/media/'.$row->id.'/poster' : null,
        ];
    }

    public function index(?Request $request=null) {
        $this->enabled();
        $filters=($request ?? request())->validate(['q'=>'nullable|string|max:100','kind'=>'nullable|in:photo,video']);
        $query=DB::table('sensaciones_media')->where('published',true);
        if(!empty($filters['q'])) $query->whereRaw('LOWER(title) LIKE ?', ['%'.mb_strtolower($filters['q']).'%']);
        if(!empty($filters['kind'])) $query->where('kind',$filters['kind']);
        return response()->json($query->latest('id')->paginate(24)->through(fn ($row) => $this->item($row)));
    }

    public function studio(Request $request) {
        $this->enabled();
        $filters=$request->validate(['q'=>'nullable|string|max:100','kind'=>'nullable|in:photo,video','status'=>'nullable|in:published,draft']);
        $query=DB::table('sensaciones_media');
        if(!empty($filters['q'])) $query->whereRaw('LOWER(title) LIKE ?', ['%'.mb_strtolower($filters['q']).'%']);
        if(!empty($filters['kind'])) $query->where('kind',$filters['kind']);
        if(!empty($filters['status'])) $query->where('published',$filters['status']==='published');
        return response()->json($query->latest('id')->paginate(24)->through(fn ($row) => $this->item($row)));
    }

    public function edit(Request $request,int $id) {
        $this->enabled();
        $data=$request->validate(['title'=>'required|string|max:150','description'=>'nullable|string|max:2000']);
        abort_unless(DB::table('sensaciones_media')->where('id',$id)->exists(),404);
        DB::table('sensaciones_media')->where('id',$id)->update(['title'=>$data['title'],'description'=>$data['description']??null,'updated_at'=>now()]);
        return response()->json($this->item(DB::table('sensaciones_media')->find($id)));
    }

    public function store(Request $request) {
        $this->enabled();
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,mp4,webm|max:3072',
        ]);
        $file = $request->file('file');
        $mime = $file->getMimeType();
        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','video/mp4'=>'mp4','video/webm'=>'webm'];
        if (!isset($allowed[$mime])) {
            throw ValidationException::withMessages(['file'=>'Formato no admitido.']);
        }
        $disk = Storage::disk('private_uploads');
        abort_if(app()->isProduction() && config('filesystems.disks.private_uploads.driver') !== 's3', 503, 'Configura almacenamiento persistente antes de subir archivos.');
        $path = $disk->putFileAs('sensaciones/media', $file, Str::uuid().'.'.$allowed[$mime]);
        abort_unless($path, 503, 'No se pudo guardar el archivo.');
        try {
            $id = DB::table('sensaciones_media')->insertGetId([
                'usuario_id'=>$request->user()->id, 'title'=>$data['title'],
                'description'=>$data['description'] ?? null,
                'kind'=>str_starts_with($mime, 'image/') ? 'photo' : 'video',
                'path'=>$path, 'mime'=>$mime, 'published'=>false,
                'created_at'=>now(), 'updated_at'=>now(),
            ]);
        } catch (\Throwable $e) {
            try { $disk->delete($path); } catch (\Throwable $cleanup) { report($cleanup); }
            throw $e;
        }
        return response()->json($this->item(DB::table('sensaciones_media')->find($id)), 201);
    }

    public function publish(Request $request, int $id) {
        $this->enabled();
        $data = $request->validate(['published'=>'required|boolean']);
        abort_unless(DB::table('sensaciones_media')->where('id',$id)->exists(),404);
        if (!$data['published']) {
            $row=DB::table('sensaciones_settings')->find(1);
            $settings=$row?(json_decode($row->settings,true)?:[]):[];
            abort_if(!empty($settings['watermark']) && (int)($settings['logo_id']??0)===$id,409,'Desactiva la marca de agua o selecciona otro logotipo antes de retirar este archivo.');
        }
        DB::table('sensaciones_media')->where('id',$id)->update(['published'=>$data['published'],'updated_at'=>now()]);
        return response()->json($this->item(DB::table('sensaciones_media')->find($id)));
    }

    public function savePoster(Request $request,int $id) {
        $this->enabled();
        abort_unless(\Illuminate\Support\Facades\Schema::hasColumn('sensaciones_media','poster_path'),503,'La actualización de portadas aún no está disponible.');
        $row=DB::table('sensaciones_media')->where('kind','video')->find($id);
        abort_unless($row,404);
        $request->validate(['poster'=>'required|file|image|mimes:jpg,jpeg,png,webp|max:1024|dimensions:max_width=4096,max_height=4096']);
        $file=$request->file('poster');
        $mime=$file->getMimeType();
        $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        abort_unless(isset($types[$mime]),422,'Formato de portada no admitido.');
        abort_if(app()->isProduction() && config('filesystems.disks.private_uploads.driver')!=='s3',503,'Almacenamiento persistente no disponible.');
        $disk=Storage::disk('private_uploads');
        $path=$disk->putFileAs('sensaciones/posters',$file,Str::uuid().'.'.$types[$mime]);
        abort_unless($path,503,'No se pudo guardar la portada.');
        try {
            $old=DB::transaction(function()use($id,$path,$mime){
                $current=DB::table('sensaciones_media')->where('kind','video')->lockForUpdate()->find($id);
                abort_unless($current,404);
                DB::table('sensaciones_media')->where('id',$id)->update(['poster_path'=>$path,'poster_mime'=>$mime,'updated_at'=>now()]);
                return $current->poster_path;
            });
        } catch(\Throwable $e) {
            try{$disk->delete($path);}catch(\Throwable $cleanup){report($cleanup);}
            throw $e;
        }
        if($old && str_starts_with($old,'sensaciones/posters/')){
            try{$disk->delete($old);}catch(\Throwable $cleanup){report($cleanup);}
        }
        return response()->json($this->item(DB::table('sensaciones_media')->find($id)));
    }

    public function poster(Request $request,int $id) {
        $this->enabled();
        $row=DB::table('sensaciones_media')->where('kind','video')->find($id);
        abort_unless($row && !empty($row->poster_path) && ($row->published || $request->user('sanctum')?->isSuperAdmin()),404);
        $disk=Storage::disk('private_uploads');
        if(config('filesystems.disks.private_uploads.driver')==='s3'){
            return redirect()->away($disk->temporaryUrl($row->poster_path,now()->addMinutes(5),[
                'ResponseContentType'=>$row->poster_mime,'ResponseContentDisposition'=>'inline',
            ]))->header('Cache-Control','private, no-store');
        }
        abort_unless($disk->exists($row->poster_path),404);
        return response()->file($disk->path($row->poster_path),['Content-Type'=>$row->poster_mime,'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }

    public function file(Request $request, int $id) {
        $this->enabled();
        $row = DB::table('sensaciones_media')->find($id);
        abort_unless($row && ($row->published || $request->user('sanctum')?->isSuperAdmin()),404);
        $marked=app(\App\Services\Sensaciones\PhotoWatermark::class)->response($row);
        if($marked) return $marked;
        $disk = Storage::disk('private_uploads');
        if (config('filesystems.disks.private_uploads.driver') === 's3') {
            return redirect()->away($disk->temporaryUrl($row->path, now()->addMinutes(5), [
                'ResponseContentType'=>$row->mime, 'ResponseContentDisposition'=>'inline',
            ]))->header('Cache-Control','private, no-store');
        }
        abort_unless($disk->exists($row->path),404);
        return response()->file($disk->path($row->path), [
            'Content-Type'=>$row->mime, 'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
