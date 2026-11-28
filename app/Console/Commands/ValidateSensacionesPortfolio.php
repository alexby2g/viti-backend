<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;

class ValidateSensacionesPortfolio extends Command {
    protected $signature='sensaciones:validate-export {directory : Carpeta privada exportada desde Sensaciones 3.0}';
    protected $description='Comprobar un paquete audiovisual sin modificar base de datos ni almacenamiento';

    public function handle(): int {
        try {
            $dir=realpath((string)$this->argument('directory'));
            if(!$dir || !is_dir($dir)) throw new \RuntimeException('Carpeta no encontrada.');
            $manifest=$dir.DIRECTORY_SEPARATOR.'manifest.json';
            if(!is_file($manifest)||is_link($manifest)||filesize($manifest)>16777216) throw new \RuntimeException('Manifiesto ausente o demasiado grande.');
            $data=json_decode(file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
            if(!is_array($data)||($data['format']??null)!=='sensaciones-portfolio-v1'||!is_array($data['media']??null)||!is_array($data['collections']??null)) throw new \RuntimeException('Formato incorrecto.');
            $known=[];$collections=[];$bytes=0;$warnings=0;
            $mediaRoot=realpath($dir.DIRECTORY_SEPARATOR.'media');
            if(!$mediaRoot||is_link($dir.DIRECTORY_SEPARATOR.'media')) throw new \RuntimeException('Carpeta multimedia inválida.');
            $types=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','mp4'=>'video/mp4','webm'=>'video/webm'];
            foreach($data['media'] as $m){
                if(!is_array($m)) throw new \RuntimeException('Entrada multimedia inválida.');
                $key=$m['key']??'';$file=$m['file']??'';
                if(!is_string($key)||!preg_match('/^(photo|video)-[1-9][0-9]*$/D',$key)||isset($known[$key])||!is_string($file)||!preg_match('~^media/(photo|video)-[1-9][0-9]*\.(jpg|jpeg|png|webp|mp4|webm)$~D',$file)) throw new \RuntimeException('Identificador o ruta inválidos.');
                $ext=pathinfo($file,PATHINFO_EXTENSION);
                $kind=str_starts_with($types[$ext],'image/')?'photo':'video';
                if($file!=='media/'.$key.'.'.$ext||!str_starts_with($key,$kind.'-')||($m['kind']??null)!==$kind) throw new \RuntimeException('Tipo inconsistente: '.$key);
                $absolute=$dir.DIRECTORY_SEPARATOR.$file;
                $real=realpath($absolute);
                if(!$real||!str_starts_with($real,$mediaRoot.DIRECTORY_SEPARATOR)||is_link($absolute)||!is_file($real)) throw new \RuntimeException('Archivo faltante o ruta insegura: '.$key);
                $size=filesize($real);
                if(!is_int($m['bytes']??null)||$size!==$m['bytes']||$size<1||!is_string($m['sha256']??null)||!hash_equals(hash_file('sha256',$real),$m['sha256'])) throw new \RuntimeException('Integridad incorrecta: '.$key);
                if((new \finfo(FILEINFO_MIME_TYPE))->file($real)!==$types[$ext]) throw new \RuntimeException('Formato real no coincide: '.$key);
                if(!is_string($m['title']??null)||trim($m['title'])===''||mb_strlen($m['title'])>150||(!is_null($m['description']??null)&&(!is_string($m['description'])||mb_strlen($m['description'])>2000))||!is_bool($m['was_published']??null)) throw new \RuntimeException('Metadatos inválidos: '.$key);
                if($size>($kind==='photo'?15728640:262144000)){$this->warn('Requiere adaptar el límite de carga: '.$key);$warnings++;}
                $known[$key]=$kind;$bytes+=$size;
            }
            foreach($data['collections'] as $c){
                if(!is_array($c)) throw new \RuntimeException('Colección inválida.');
                $key=$c['key']??'';$kind=$c['kind']??'';$ids=$c['media_keys']??null;
                if(!is_string($key)||!preg_match('/^(project|comparison)-[1-9][0-9]*$/D',$key)||isset($collections[$key])||!in_array($kind,['album','project','comparison'],true)||!is_array($ids)||!array_is_list($ids)||!count($ids)) throw new \RuntimeException('Colección inválida.');
                if(!is_string($c['title']??null)||trim($c['title'])===''||mb_strlen($c['title'])>150||(!is_null($c['description']??null)&&(!is_string($c['description'])||mb_strlen($c['description'])>2000))||!is_bool($c['was_published']??null)) throw new \RuntimeException('Metadatos inválidos: '.$key);
                foreach($ids as $id){if(!is_string($id)||!isset($known[$id])||($kind!=='project'&&$known[$id]!=='photo')) throw new \RuntimeException('Referencia inválida: '.$key);}
                if(count(array_unique($ids))!==count($ids)||($kind==='comparison'&&count($ids)!==2)) throw new \RuntimeException('Cantidad o duplicados inválidos: '.$key);
                $cover=$c['cover_key']??null;
                if($cover!==null&&(!is_string($cover)||!in_array($cover,$ids,true)||$known[$cover]!=='photo')) throw new \RuntimeException('Portada inválida: '.$key);
                if(count($ids)>40){$this->warn('Colección supera 40 elementos: '.$key);$warnings++;}
                $collections[$key]=true;
            }
            $this->info(count($known).' archivos; '.count($collections).' colecciones; '.$bytes.' bytes; '.$warnings.' advertencias.');
            $this->info('Validación de lectura terminada. No se ha importado ni publicado nada.');
            return $warnings?self::FAILURE:self::SUCCESS;
        } catch(\Throwable $e){$this->error('Validación fallida: '.$e->getMessage());return self::FAILURE;}
    }
}
