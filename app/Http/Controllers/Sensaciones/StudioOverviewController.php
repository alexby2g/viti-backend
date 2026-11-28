<?php
namespace App\Http\Controllers\Sensaciones;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StudioOverviewController extends Controller
{
    public function show() {
        $ready = config('sensaciones.enabled')
            && Schema::hasTable('sensaciones_media')
            && Schema::hasTable('sensaciones_collections')
            && Schema::hasTable('sensaciones_settings')
            && Schema::hasTable('sensaciones_uploads');
        $persistent = config('filesystems.disks.private_uploads.driver') === 's3' || !app()->isProduction();
        $direct = config('sensaciones.direct_uploads') && config('filesystems.disks.private_uploads.driver') === 's3';
        $data = ['ready'=>(bool)$ready,'uploads_available'=>(bool)($ready && $persistent),
            'direct_uploads'=>(bool)($ready && $direct),'max_video_bytes'=>$direct?262144000:3145728,
            'max_photo_bytes'=>$direct?15728640:3145728,'counts'=>null];
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
