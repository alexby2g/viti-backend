<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $requestId = 'VITI-'.Str::upper((string)Str::ulid());
        $clientRequestId = $this->clientRequestId($request);
        $request->attributes->set('viti_request_id',$requestId);
        if ($clientRequestId) $request->attributes->set('viti_client_request_id',$clientRequestId);

        $response = $next($request);
        $duration = round((microtime(true)-$started)*1000,1);
        $release = $this->release();

        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('X-Frame-Options','SAMEORIGIN');
        $response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy','camera=(self), microphone=(), geolocation=(self)');
        $response->headers->set('X-Permitted-Cross-Domain-Policies','none');
        $response->headers->set('X-VITI-Request-ID',$requestId);
        $response->headers->set('X-VITI-Duration-Ms',(string)$duration);
        $response->headers->set('X-VITI-Backend-Version',$release);
        if ($clientRequestId) $response->headers->set('X-VITI-Client-Request-ID',$clientRequestId);

        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control','no-store, private');
            $response->headers->set('Pragma','no-cache');
            $response->headers->set('X-Frame-Options','DENY');
            $response->headers->set('Content-Security-Policy',"default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        }

        if (app()->environment('production') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security','max-age=31536000; includeSubDomains');
        }

        if ($duration >= 2000) {
            Log::warning('VITI slow request',[
                'request_id'=>$requestId,
                'client_request_id'=>$clientRequestId,
                'method'=>$request->method(),
                'path'=>$request->path(),
                'status'=>$response->getStatusCode(),
                'duration_ms'=>$duration,
                'usuario_id'=>$request->user()?->id,
                'empresa_id'=>$request->attributes->get('viti_empresa_id'),
                'release'=>$release,
            ]);
        }

        return $response;
    }

    private function release(): string
    {
        $sha = (string)(env('RENDER_GIT_COMMIT') ?: env('VITI_RELEASE_SHA') ?: 'local');
        return $sha === 'local' ? $sha : substr($sha,0,12);
    }

    private function clientRequestId(Request $request): ?string
    {
        $value = trim((string)$request->header('X-VITI-Client-Request-ID'));
        if ($value === '' || strlen($value) > 80) return null;
        return preg_match('/^[A-Za-z0-9._:-]+$/',$value) ? $value : null;
    }
}
