<?php
namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SensacionesImportTest extends TestCase {
    private ?string $folder=null;
    protected function setUp(): void {
        parent::setUp();
        if(config('database.default')!=='sqlite')$this->markTestSkipped('Importación con disco local: prueba SQLite.');
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(':memory:',config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
        Schema::create('usuarios',function(Blueprint $t){$t->id();$t->string('rol');$t->string('estado');});
        DB::table('usuarios')->insert(['id'=>1,'rol'=>'superadmin','estado'=>'activo']);
        foreach(['2026_11_27_230000_create_sensaciones_media_table.php','2026_11_27_230001_create_sensaciones_collections.php','2026_11_27_230003_create_sensaciones_import_items.php'] as $f)(require database_path('migrations/'.$f))->up();
        Storage::fake('private_uploads');
        config(['filesystems.disks.private_uploads.driver'=>'local','sensaciones.import_enabled'=>false]);
        $this->folder=sys_get_temp_dir().'/sensaciones-test-'.Str::uuid();
        mkdir($this->folder,0700);mkdir($this->folder.'/media',0700);
        $raw=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aVxkAAAAASUVORK5CYII=');
        file_put_contents($this->folder.'/media/photo-1.png',$raw);
        $this->manifest('Foto QA',$raw);
    }
    private function manifest(string $title,string $raw):void {
        file_put_contents($this->folder.'/manifest.json',json_encode(['format'=>'sensaciones-portfolio-v1','media'=>[['key'=>'photo-1','kind'=>'photo','title'=>$title,'description'=>null,'file'=>'media/photo-1.png','bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),'was_published'=>true]],'collections'=>[['key'=>'project-1','kind'=>'album','title'=>'Álbum QA','description'=>null,'media_keys'=>['photo-1'],'cover_key'=>'photo-1','was_published'=>true]]],JSON_THROW_ON_ERROR));
    }
    private function args():array {return ['directory'=>$this->folder,'--source'=>'laragon-qa','--owner'=>'1','--apply'=>true];}
    public function test_simulation_and_disabled_apply_write_nothing():void {
        $this->artisan('sensaciones:import-export',['directory'=>$this->folder])->assertSuccessful();
        $this->artisan('sensaciones:import-export',$this->args())->assertFailed();
        $this->assertDatabaseCount('sensaciones_media',0);
        $this->assertDatabaseCount('sensaciones_import_items',0);
        $this->assertSame([],Storage::disk('private_uploads')->allFiles());
    }
    public function test_import_retries_without_duplicates_and_refuses_changed_metadata():void {
        config(['sensaciones.import_enabled'=>true]);
        $this->artisan('sensaciones:import-export',$this->args())->assertSuccessful();
        $this->artisan('sensaciones:import-export',$this->args())->assertSuccessful();
        $this->assertDatabaseCount('sensaciones_media',1);
        $this->assertDatabaseCount('sensaciones_collections',1);
        $this->assertDatabaseCount('sensaciones_import_items',2);
        $m=DB::table('sensaciones_media')->first();
        $c=DB::table('sensaciones_collections')->first();
        $this->assertFalse((bool)$m->published);$this->assertFalse((bool)$c->published);
        $this->assertSame([(int)$m->id],json_decode($c->media_ids,true));
        $this->assertSame((int)$m->id,(int)$c->cover_id);
        Storage::disk('private_uploads')->assertExists($m->path);
        $this->manifest('Cambio no autorizado',file_get_contents($this->folder.'/media/photo-1.png'));
        $this->artisan('sensaciones:import-export',$this->args())->assertFailed();
        $this->assertDatabaseHas('sensaciones_media',['id'=>$m->id,'title'=>'Foto QA']);
    }
    protected function tearDown():void {
        if($this->folder)File::deleteDirectory($this->folder);
        parent::tearDown();
    }
}
