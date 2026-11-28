<?php
namespace App\Http\Controllers\Sensaciones;
use App\Http\Controllers\Controller;
class SitemapController extends Controller {
    public function index() {
        abort_unless(config('sensaciones.enabled'),404);
        $base=rtrim((string)config('sensaciones.site_url'),'/');
        abort_unless(filter_var($base,FILTER_VALIDATE_URL) && parse_url($base,PHP_URL_SCHEME)==='https' && !parse_url($base,PHP_URL_QUERY) && !parse_url($base,PHP_URL_FRAGMENT),404);
        $xml='<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach(['/sensaciones','/sensaciones/portafolio'] as $route){
            $xml.='<url><loc>'.htmlspecialchars($base.$route,ENT_XML1|ENT_QUOTES,'UTF-8').'</loc></url>';
        }
        return response($xml.'</urlset>',200,['Content-Type'=>'application/xml; charset=UTF-8']);
    }
}
