<?php

namespace App\Http\Controllers\Sensaciones;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller {
    private function enabled(): void { abort_unless(config('sensaciones.enabled'),404); }
    private function defaults(): array {
        return [
            'brand'=>'Sensaciones Audiovisuales Digital',
            'headline'=>'Historias que merecen ser vistas.',
            'tagline'=>'Fotografía, video y creatividad con una mirada propia.',
            'primary'=>'#FF681E','secondary'=>'#980B20','background'=>'#101014',
            'logo_id'=>null,'intro_id'=>null,'whatsapp'=>null,
            'seo_title'=>'Sensaciones Audiovisuales Digital',
            'seo_description'=>'Producción audiovisual, fotografía y arte digital.',
            'services'=>[
                ['title'=>'Producción audiovisual','description'=>'Videos, eventos y producciones creativas.'],
                ['title'=>'Fotografía profesional','description'=>'Sesiones fotográficas y edición.'],
                ['title'=>'Publicidad y diseño','description'=>'Contenido creativo para marcas y empresas.'],
            ],
        ];
    }
    private function values(): array {
        $row=DB::table('sensaciones_settings')->find(1);
        return array_replace($this->defaults(),$row ? (json_decode($row->settings,true) ?: []) : []);
    }
    private function mediaUrl($id,string $kind): ?string {
        if (!$id) return null;
        $exists=DB::table('sensaciones_media')->where('id',$id)->where('kind',$kind)->where('published',true)->exists();
        return $exists ? '/api/v1/sensaciones/media/'.$id.'/file' : null;
    }
    public function show() {
        $this->enabled();
        $data=$this->values();
        $data['logo_url']=$this->mediaUrl($data['logo_id'],'photo');
        $data['intro_url']=$this->mediaUrl($data['intro_id'],'video');
        unset($data['logo_id'],$data['intro_id']);
        return response()->json($data);
    }
    public function studio() { $this->enabled(); return response()->json($this->values()); }
    public function save(Request $request) {
        $this->enabled();
        $data=$request->validate([
            'brand'=>'required|string|max:100','headline'=>'required|string|max:180','tagline'=>'required|string|max:500',
            'primary'=>['required','regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary'=>['required','regex:/^#[0-9a-fA-F]{6}$/'],
            'background'=>['required','regex:/^#[0-9a-fA-F]{6}$/'],
            'logo_id'=>'nullable|integer','intro_id'=>'nullable|integer',
            'whatsapp'=>['nullable','regex:/^[1-9][0-9]{7,14}$/'],
            'seo_title'=>'required|string|max:70','seo_description'=>'required|string|max:160',
            'services'=>'present|array|max:12','services.*'=>'array:title,description',
            'services.*.title'=>'required|string|max:100','services.*.description'=>'required|string|max:500',
        ]);
        foreach(['logo_id'=>'photo','intro_id'=>'video'] as $key=>$kind){
            if(!empty($data[$key]) && !$this->mediaUrl($data[$key],$kind))
                throw ValidationException::withMessages([$key=>'Selecciona un archivo publicado del tipo correcto.']);
        }
        $values=array_replace($this->defaults(),$data);
        DB::table('sensaciones_settings')->upsert([['id'=>1,'settings'=>json_encode($values,JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]],['id'],['settings','updated_at']);
        return response()->json($values);
    }
}
