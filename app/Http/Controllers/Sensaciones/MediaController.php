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
