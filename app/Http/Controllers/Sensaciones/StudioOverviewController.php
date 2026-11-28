<?php
namespace App\Http\Controllers\Sensaciones;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StudioOverviewController extends Controller
{
    public function show() {
        $enabled=(bool)config('sensaciones.enabled');
        $tables=[];
        $databaseAvailable=false;
        try {
            DB::connection()->getPdo();
            $databaseAvailable=true;
            foreach(['sensaciones_media','sensaciones_collections','sensaciones_settings','sensaciones_uploads','sensaciones_import_items'] as $table) {
                $tables[$table]=Schema::hasTable($table);
            }
        } catch (\Throwable $e) {
            report($e);
            $databaseAvailable=false;
        }
        $schemaReady=$databaseAvailable && count($tables)===5 && !in_array(false,$tables,true);
        $ready=$enabled && $schemaReady;
        $disk=config('filesystems.disks.private_uploads',[]);
        $s3=($disk['driver']??null)==='s3';
        $configured=$s3 && !empty($disk['bucket']) && !empty($disk['key']) && !empty($disk['secret']) && !empty($disk['endpoint']);
        $persistent=$configured || (!app()->isProduction() && ($disk['driver']??null)==='local');
        $direct=config('sensaciones.direct_uploads') && $configured;
        $data = ['ready'=>(bool)$ready,'uploads_available'=>(bool)($ready && $persistent),
            'direct_uploads'=>(bool)($ready && $direct),'max_video_bytes'=>$direct?262144000:3145728,
            'max_photo_bytes'=>$direct?15728640:3145728,'counts'=>null,
            'diagnostics'=>[
                'module_enabled'=>$enabled,'database_available'=>$databaseAvailable,
                'database_driver'=>config('database.default'),'schema_ready'=>$schemaReady,'tables'=>$tables,
                'storage_driver'=>$disk['driver']??'unknown','storage_configured'=>$persistent,
                'storage_connection_verified'=>false,'large_uploads_enabled'=>(bool)$direct,
                'watermark_available'=>(bool)(config('sensaciones.watermark_available') && extension_loaded('gd')),
            ]];
        if ($ready) {
            $data['counts']=[
                'photos'=>DB::table('sensaciones_media')->where('kind','photo')->count(),
                'videos'=>DB::table('sensaciones_media')->where('kind','video')->count(),
                'published'=>DB::table('sensaciones_media')->where('published',true)->count(),
                'drafts'=>DB::table('sensaciones_media')->where('published',false)->count(),
                'albums'=>DB::table('sensaciones_collections')->where('kind','album')->count(),
                'projects'=>DB::table('sensaciones_collections')->where('kind','project')->count(),
                'comparisons'=>DB::table('sensaciones_collections')->where('kind','comparison')->count(),
            ];
        }
        return response()->json($data)->header('Cache-Control','private, no-store');
    }
}
