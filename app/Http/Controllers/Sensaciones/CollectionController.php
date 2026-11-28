<?php
namespace App\Http\Controllers\Sensaciones;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectionController extends Controller {
    private function enabled(): void { abort_unless(config('sensaciones.enabled'),404); }
    private function present($row, bool $admin = false): array {
        $ids = json_decode($row->media_ids, true) ?: [];
        $query = DB::table('sensaciones_media')->whereIn('id', $ids);
        if (!$admin) $query->where('published',true);
        $media = $query->get()->keyBy('id');
        $items = collect($ids)->map(fn($id) => $media->get($id))->filter()->map(fn($m)=>[
            'id'=>$m->id,'title'=>$m->title,'kind'=>$m->kind,'published'=>(bool)$m->published,
            'url'=>'/api/v1/sensaciones/media/'.$m->id.'/file',
            'poster_url'=>!empty($m->poster_path)?'/api/v1/sensaciones/media/'.$m->id.'/poster':null,
        ])->values();
        $cover = $items->first(fn($m)=>$m['id']==$row->cover_id && $m['kind']==='photo')
            ?? $items->first(fn($m)=>$m['kind']==='photo');
        return ['id'=>$row->id,'kind'=>$row->kind,'title'=>$row->title,'description'=>$row->description,
            'published'=>(bool)$row->published,'cover_id'=>$row->cover_id,'media_ids'=>$ids,
            'cover'=>$cover['url']??null,'items'=>$items];
    }
    public function index(?Request $request=null) {
        $this->enabled();
        $data=($request ?? request())->validate(['view'=>'nullable|in:projects,comparisons']);
        $query=DB::table('sensaciones_collections')->where('published',true);
        if(($data['view']??null)==='projects') $query->whereIn('kind',['album','project']);
        if(($data['view']??null)==='comparisons') $query->where('kind','comparison');
        return response()->json($query->latest('id')->paginate(12)
          ->through(function($row){ $data=$this->present($row); unset($data['media_ids']); return $data; }));
    }
    public function studio() {
        $this->enabled();
        return response()->json(DB::table('sensaciones_collections')->latest('id')->paginate(12)->through(fn($r)=>$this->present($r,true)));
    }
    public function save(Request $request, ?int $id=null) {
        $this->enabled();
        $data=$request->validate([
            'kind'=>'required|in:album,project,comparison',
            'title'=>'required|string|max:150','description'=>'nullable|string|max:2000',
            'media_ids'=>'required|array|min:1|max:40','media_ids.*'=>'required|integer|distinct|exists:sensaciones_media,id',
            'cover_id'=>'nullable|integer','published'=>'required|boolean',
        ]);
        $ids=array_map('intval',$data['media_ids']);
        $rows=DB::table('sensaciones_media')->whereIn('id',$ids)->get()->keyBy('id');
        $bad=($data['kind']!=='project' && $rows->contains(fn($m)=>$m->kind!=='photo'))
          || ($data['kind']==='comparison' && count($ids)!==2)
          || (!empty($data['cover_id']) && (!in_array((int)$data['cover_id'],$ids,true) || $rows->get($data['cover_id'])?->kind!=='photo'))
          || ($data['published'] && $rows->contains(fn($m)=>!$m->published));
        if($bad) throw ValidationException::withMessages(['media_ids'=>'Revisa el tipo, portada y publicación de los archivos. Para comparar, selecciona exactamente dos fotos.']);
        $values=['kind'=>$data['kind'],'title'=>$data['title'],'description'=>$data['description']??null,
          'media_ids'=>json_encode($ids,JSON_THROW_ON_ERROR),'cover_id'=>$data['cover_id']??null,'published'=>$data['published'],'updated_at'=>now()];
        if($id!==null){abort_unless(DB::table('sensaciones_collections')->where('id',$id)->exists(),404);DB::table('sensaciones_collections')->where('id',$id)->update($values);}
        else {$id=DB::table('sensaciones_collections')->insertGetId($values+['created_at'=>now()]);}
        return response()->json($this->present(DB::table('sensaciones_collections')->find($id),true));
    }
}
