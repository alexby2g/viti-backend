<?php

namespace Tests\Feature;

use App\Http\Controllers\Sensaciones\SettingsController;
use App\Http\Controllers\Sensaciones\SitemapController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SensacionesPremiumTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();
        // Estas pruebas jamás deben crear tablas en la base de producción.
        $this->assertTrue(app()->environment('testing'));
        $driver=config('database.default');
        $this->assertContains($driver,['sqlite','pgsql']);
        $this->assertEmpty(config("database.connections.{$driver}.url"));
        if($driver==='sqlite'){
            $this->assertSame(':memory:',config('database.connections.sqlite.database'));
        }else{
            $this->assertSame('1',getenv('SENSACIONES_CI_POSTGRES'));
            $this->assertSame('127.0.0.1',config('database.connections.pgsql.host'));
            $this->assertSame('sensaciones_qa_ci',config('database.connections.pgsql.database'));
            $this->assertSame('sensaciones_qa_ci',DB::selectOne('select current_database() as db')->db);
        }
        // Cada prueba se revierte, incluso en PostgreSQL efímero.
        DB::beginTransaction();
        foreach([
            '2026_11_27_230000_create_sensaciones_media_table.php',
            '2026_11_27_230001_create_sensaciones_collections.php',
            '2026_11_27_230002_create_sensaciones_settings.php',
            '2026_11_27_230003_create_sensaciones_import_items.php',
        ] as $file) (require database_path('migrations/'.$file))->up();
        config(['sensaciones.enabled'=>true]);
    }

    protected function tearDown(): void {
        if(DB::transactionLevel()>0) DB::rollBack();
        parent::tearDown();
    }

    public function test_public_catalog_excludes_drafts(): void {
        foreach([true,false] as $published){
            DB::table('sensaciones_media')->insert(['usuario_id'=>1,'title'=>$published?'Público':'Privado','kind'=>'photo','path'=>'test.jpg','mime'=>'image/jpeg','published'=>$published,'created_at'=>now(),'updated_at'=>now()]);
        }
        $data=app(\App\Http\Controllers\Sensaciones\MediaController::class)->index()->getData(true);
        $this->assertCount(1,$data['data']);
        $this->assertSame('Público',$data['data'][0]['title']);
        $this->assertArrayNotHasKey('path',$data['data'][0]);
    }

    public function test_public_collections_hide_unpublished_members(): void {
        $ids=[];
        foreach([true,false] as $published){
            $ids[]=DB::table('sensaciones_media')->insertGetId(['usuario_id'=>1,'title'=>$published?'Visible':'Oculto','kind'=>'photo','path'=>'test.jpg','mime'=>'image/jpeg','published'=>$published,'created_at'=>now(),'updated_at'=>now()]);
        }
        DB::table('sensaciones_collections')->insert(['kind'=>'album','title'=>'Álbum QA','media_ids'=>json_encode($ids),'cover_id'=>$ids[1],'published'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $item=app(\App\Http\Controllers\Sensaciones\CollectionController::class)->index()->getData(true)['data'][0];
        $this->assertCount(1,$item['items']);
        $this->assertSame('Visible',$item['items'][0]['title']);
        $this->assertSame('/api/v1/sensaciones/media/'.$ids[0].'/file',$item['cover']);
        $this->assertArrayNotHasKey('media_ids',$item);
    }

    public function test_studio_overview_reports_disabled_without_fake_counts(): void {
        config(['sensaciones.enabled'=>false]);
        $data=app(\App\Http\Controllers\Sensaciones\StudioOverviewController::class)->show()->getData(true);
        $this->assertFalse($data['ready']);
        $this->assertFalse($data['uploads_available']);
        $this->assertNull($data['counts']);
        $this->assertFalse($data['diagnostics']['module_enabled']);
        $this->assertTrue($data['diagnostics']['schema_ready']);
        $this->assertFalse($data['diagnostics']['storage_connection_verified']);
    }

    public function test_studio_overview_reports_real_counts(): void {
        $data=app(\App\Http\Controllers\Sensaciones\StudioOverviewController::class)->show()->getData(true);
        $this->assertTrue($data['ready']);
        $this->assertSame(0,$data['counts']['photos']);
        $this->assertSame(0,$data['counts']['drafts']);
    }

    public function test_default_brand_and_services_have_no_private_media(): void {
        $data=app(SettingsController::class)->show()->getData(true);
        $this->assertSame('Sensaciones Audiovisuales Digital',$data['brand']);
        $this->assertNull($data['logo_url']);
        $this->assertNull($data['intro_url']);
        $this->assertArrayNotHasKey('logo_id',$data);
        $this->assertNotEmpty($data['services']);
    }

    public function test_draft_cannot_be_used_as_public_intro(): void {
        $id=DB::table('sensaciones_media')->insertGetId([
            'usuario_id'=>1,'title'=>'Borrador','kind'=>'video','path'=>'private.mp4',
            'mime'=>'video/mp4','published'=>false,'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('sensaciones_settings')->insert(['id'=>1,'settings'=>json_encode(['intro_id'=>$id]),'created_at'=>now(),'updated_at'=>now()]);
        $this->assertNull(app(SettingsController::class)->show()->getData(true)['intro_url']);
        DB::table('sensaciones_media')->where('id',$id)->update(['published'=>true]);
        $this->assertSame('/api/v1/sensaciones/media/'.$id.'/file',app(SettingsController::class)->show()->getData(true)['intro_url']);
    }

    public function test_studio_routes_require_superadmin_and_sanctum(): void {
        $routes=collect(Route::getRoutes()->getRoutes())->filter(fn($r)=>str_starts_with($r->uri(),'api/v1/sensaciones/studio/'));
        $this->assertGreaterThan(5,$routes->count());
        foreach($routes as $route){
            $this->assertContains('auth:sanctum',$route->gatherMiddleware());
            $this->assertContains('superadmin',$route->gatherMiddleware());
        }
    }

    public function test_sitemap_contains_only_public_routes(): void {
        config(['sensaciones.site_url'=>'https://example.com']);
        $xml=app(SitemapController::class)->index()->getContent();
        $this->assertStringContainsString('https://example.com/sensaciones/portafolio',$xml);
        $this->assertStringNotContainsString('/studio',$xml);
        $this->assertStringNotContainsString('/login',$xml);
    }
}
