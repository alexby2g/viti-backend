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
        $this->assertSame('sqlite',config('database.default'));
        $this->assertSame(':memory:',config('database.connections.sqlite.database'));
        foreach([
            '2026_11_27_230000_create_sensaciones_media_table.php',
            '2026_11_27_230001_create_sensaciones_collections.php',
            '2026_11_27_230002_create_sensaciones_settings.php',
        ] as $file) (require database_path('migrations/'.$file))->up();
        config(['sensaciones.enabled'=>true]);
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
