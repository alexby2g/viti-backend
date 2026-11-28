<?php

namespace App\Services\Sensaciones;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PhotoWatermark
{
    private function read($disk, string $path, int $maxPixels): \GdImage {
        abort_unless($disk->exists($path),404);
        abort_if($disk->size($path)>15728640,422,'Imagen demasiado grande para la marca de agua.');
        $raw=$disk->get($path);
        $info=is_string($raw)?@getimagesizefromstring($raw):false;
        abort_unless($info && $info[0]>0 && $info[1]>0 && $info[0]*$info[1]<=$maxPixels,422,'Resolución no compatible con la marca de agua.');
        $image=@imagecreatefromstring($raw);
        abort_unless($image,422,'No se pudo procesar la imagen.');
        return $image;
    }
    public function response($row) {
        if($row->kind!=='photo'||!$row->published) return null;
        $stored=DB::table('sensaciones_settings')->find(1);
        $settings=$stored?(json_decode($stored->settings,true)?:[]):[];
        if(empty($settings['watermark'])) return null;
        // El logotipo es un recurso público de marca, no una fotografía a proteger.
        if((int)$row->id===(int)($settings['logo_id']??0)) return null;
        abort_unless(config('sensaciones.watermark_available') && extension_loaded('gd'),503,'Marca de agua no disponible en el servidor.');
        $logo=DB::table('sensaciones_media')->where('id',$settings['logo_id']??0)->where('kind','photo')->where('published',true)->first();
        abort_unless($logo,503,'La marca de agua necesita un logotipo publicado.');
        $disk=Storage::disk('private_uploads');
        $photo=$this->read($disk,$row->path,6000000);
        $mark=$this->read($disk,$logo->path,1000000);
        $w=imagesx($photo);$h=imagesy($photo);
        $scale=min($w*.23/imagesx($mark),$h*.18/imagesy($mark),1);
        $mw=max(1,(int)round(imagesx($mark)*$scale));$mh=max(1,(int)round(imagesy($mark)*$scale));
        $margin=max(2,(int)round(min($w,$h)*.025));
        $x=max(0,$w-$mw-$margin);$y=max(0,$h-$mh-$margin);
        $canvas=imagecreatetruecolor($w,$h);
        abort_unless($canvas,503);
        imagefill($canvas,0,0,imagecolorallocate($canvas,12,12,12));
        imagealphablending($canvas,true);
        imagecopy($canvas,$photo,0,0,0,0,$w,$h);
        imagecopyresampled($canvas,$mark,$x,$y,0,0,$mw,$mh,imagesx($mark),imagesy($mark));
        ob_start();
        try { $ok=imagejpeg($canvas,null,88);$jpeg=ob_get_contents(); }
        finally { ob_end_clean(); }
        abort_unless($ok && is_string($jpeg) && $jpeg!=='',503,'No se pudo generar la copia protegida.');
        return response($jpeg,200,['Content-Type'=>'image/jpeg','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
