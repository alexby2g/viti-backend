<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Usuario;

class ImportSensacionesPortfolio extends Command
{
    protected $signature='sensaciones:import-export {directory : Carpeta privada del exportador 3.0} {--source= : Identificador estable de la instalación de origen} {--owner= : ID de superadmin QA existente} {--apply : Ejecutar escrituras en QA}';
    protected $description='Simular o importar un portafolio oficial como borradores en QA';

    private function fingerprint(array $data): string {
        ksort($data);
        return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    private function existing(string $source,string $key,string $fingerprint,string $kind,string $table): ?object {
        $row=DB::table('sensaciones_import_items')->where('source',$source)->where('source_key',$key)->first();
        if(!$row)return null;
        if($row->fingerprint!==$fingerprint || $row->kind!==$kind)throw new \RuntimeException('Conflicto de origen: '.$key.'. No se sobrescribirá el contenido previo.');
        $target=DB::table($table)->find($row->target_id);
        if(!$target)throw new \RuntimeException('Registro previamente importado no encontrado: '.$key);
        return $target;
    }

    private function record(string $source,string $key,string $fingerprint,string $kind,int $id): void {
        DB::table('sensaciones_import_items')->insert(['source'=>$source,'source_key'=>$key,'fingerprint'=>$fingerprint,'kind'=>$kind,'target_id'=>$id,'created_at'=>now(),'updated_at'=>now()]);
    }

    private function guardTarget(): void {
        if(!config('sensaciones.import_enabled'))throw new \RuntimeException('Importación QA desactivada.');
        $driver=DB::connection()->getDriverName();
        if($driver==='pgsql'){
            $db=DB::selectOne('select current_database() as db')->db;
            if($db!=='sensaciones_qa')throw new \RuntimeException('La base PostgreSQL debe ser sensaciones_qa.');
        }elseif($driver==='sqlite' && app()->environment(['local','testing'])){
            $db=DB::connection()->getDatabaseName();
            if($db!==':memory:' && basename($db)!=='sensaciones_qa.sqlite')throw new \RuntimeException('Archivo SQLite no autorizado para QA.');
        }else throw new \RuntimeException('Motor o entorno no autorizado para importar.');
        $disk=config('filesystems.disks.private_uploads');
        if(($disk['driver']??null)==='s3'){
            if(($disk['bucket']??null)!=='sensaciones-qa-private')throw new \RuntimeException('Se requiere el bucket privado aislado sensaciones-qa-private.');
        }elseif(($disk['driver']??null)!=='local' || !app()->environment(['local','testing'])){
            throw new \RuntimeException('Almacenamiento no autorizado para QA.');
        }
    }

    private function verifyStored($disk,string $path,string $sha,int $size): void {
        if(!$disk->exists($path)||$disk->size($path)!==$size)throw new \RuntimeException('Archivo almacenado ausente o incompleto.');
        $stream=$disk->readStream($path);
        if(!is_resource($stream))throw new \RuntimeException('No se pudo verificar la copia.');
        try {$ctx=hash_init('sha256');$n=hash_update_stream($ctx,$stream);$actual=hash_final($ctx);}
        finally {fclose($stream);}
        if($n!==$size||!hash_equals($sha,$actual))throw new \RuntimeException('La copia no coincide con el SHA-256.');
    }

    public function handle(): int {
        try {
            $dir=realpath((string)$this->argument('directory'));
            if(!$dir)throw new \RuntimeException('Carpeta inexistente.');
            $manifest=$dir.DIRECTORY_SEPARATOR.'manifest.json';
            if(!is_file($manifest)||is_link($manifest)||filesize($manifest)>16777216)throw new \RuntimeException('Manifiesto inválido.');
            $raw=file_get_contents($manifest);
            if($raw===false)throw new \RuntimeException('No se pudo leer el manifiesto.');
            if($this->call('sensaciones:validate-export',['directory'=>$dir])!==self::SUCCESS)return self::FAILURE;
            if(!hash_equals(hash('sha256',$raw),hash_file('sha256',$manifest)))throw new \RuntimeException('El manifiesto cambió durante la validación.');
            $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if(!$this->option('apply')){
                $this->info('SIMULACIÓN: '.count($data['media']).' archivos y '.count($data['collections']).' colecciones. No se consultó ni modificó la base de datos desde este comando.');
                $this->line('Todos los nuevos registros se importarán como borradores.');
                return self::SUCCESS;
            }
            $source=(string)$this->option('source');
            $owner=(string)$this->option('owner');
            if(!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$source)||!preg_match('/^[1-9][0-9]*$/D',$owner))throw new \RuntimeException('Indica --source y --owner válidos.');
            $this->guardTarget();
            $user=Usuario::query()->find($owner);
            if(!$user||!$user->isSuperAdmin()||$user->estado!=='activo')throw new \RuntimeException('El propietario debe ser un superadmin QA activo.');
            $disk=Storage::disk('private_uploads');
            $map=[];$added=0;$skipped=0;
            // Preflight de conflictos: no actualizar registros existentes.
            foreach(['media'=>'sensaciones_media','collections'=>'sensaciones_collections'] as $group=>$table){
                foreach($data[$group] as $item)$this->existing($source,$item['key'],$this->fingerprint($item),$group,$table);
            }
            foreach($data['media'] as $item){
                $fp=$this->fingerprint($item);
                $old=$this->existing($source,$item['key'],$fp,'media','sensaciones_media');
                if($old){
                    $this->verifyStored($disk,$old->path,$item['sha256'],$item['bytes']);
                    $map[$item['key']]=(int)$old->id;$skipped++;continue;
                }
                $ext=pathinfo($item['file'],PATHINFO_EXTENSION);
                $mime=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','mp4'=>'video/mp4','webm'=>'video/webm'][$ext];
                $file=$dir.DIRECTORY_SEPARATOR.$item['file'];
                if(is_link($file)||!is_file($file)||filesize($file)!==$item['bytes']||!hash_equals($item['sha256'],hash_file('sha256',$file)))throw new \RuntimeException('Archivo cambió desde la validación: '.$item['key']);
                $path='sensaciones/imports/'.$source.'/'.$item['key'].'-'.$item['sha256'].'.'.$ext;
                if(!$disk->exists($path)){
                    $stream=fopen($file,'rb');
                    if(!is_resource($stream))throw new \RuntimeException('No se pudo abrir el archivo.');
                    try {if(!$disk->put($path,$stream))throw new \RuntimeException('Fallo al guardar copia privada.');}
                    finally {fclose($stream);}
                }
                $this->verifyStored($disk,$path,$item['sha256'],$item['bytes']);
                $id=DB::transaction(function()use($item,$source,$fp,$mime,$path,$user){
                    $existing=$this->existing($source,$item['key'],$fp,'media','sensaciones_media');
                    if($existing)return (int)$existing->id;
                    $id=DB::table('sensaciones_media')->insertGetId(['usuario_id'=>$user->id,'title'=>$item['title'],'description'=>$item['description']??null,'kind'=>$item['kind'],'path'=>$path,'mime'=>$mime,'published'=>false,'created_at'=>now(),'updated_at'=>now()]);
                    $this->record($source,$item['key'],$fp,'media',$id);
                    return (int)$id;
                });
                $map[$item['key']]=$id;$added++;
            }
            foreach($data['collections'] as $item){
                $fp=$this->fingerprint($item);
                if($this->existing($source,$item['key'],$fp,'collections','sensaciones_collections')){$skipped++;continue;}
                DB::transaction(function()use($item,$source,$fp,$map){
                    if($this->existing($source,$item['key'],$fp,'collections','sensaciones_collections'))return;
                    $ids=array_map(fn($key)=>$map[$key],$item['media_keys']);
                    $cover=$item['cover_key']??null;
                    $id=DB::table('sensaciones_collections')->insertGetId(['kind'=>$item['kind'],'title'=>$item['title'],'description'=>$item['description']??null,'media_ids'=>json_encode($ids,JSON_THROW_ON_ERROR),'cover_id'=>$cover===null?null:$map[$cover],'published'=>false,'created_at'=>now(),'updated_at'=>now()]);
                    $this->record($source,$item['key'],$fp,'collections',$id);
                });
                $added++;
            }
            $this->info('Registros procesados: '.$added.' nuevos; '.$skipped.' ya importados. No se publicaron archivos.');
            return self::SUCCESS;
        }catch(\Throwable $e){
            $this->error('Importación detenida. Las operaciones completadas pueden permanecer como borradores; reintenta con el mismo origen tras revisar el error.');
            if($e instanceof \RuntimeException)$this->error($e->getMessage());else $this->error('Error de infraestructura. Revisar logs protegidos.');
            return self::FAILURE;
        }
    }
}
