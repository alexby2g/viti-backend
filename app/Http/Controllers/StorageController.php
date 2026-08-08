<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class StorageController extends Controller
{
    public function status(): JsonResponse
    {
        $public = $this->probe('public', true);
        $private = $this->probe('private_uploads', false);

        return response()->json([
            'data' => [
                'publico' => $public,
                'privado' => $private,
                'listo' => $public['ok'] && $private['ok'],
                'configuracion' => $this->configurationSummary(),
            ],
        ]);
    }

    private function configurationSummary(): array
    {
        $endpoint = trim((string) config('filesystems.disks.public.endpoint'));
        $publicUrl = trim((string) config('filesystems.disks.public.url'));

        return [
            'access_key' => filled(config('filesystems.disks.public.key')),
            'secret_key' => filled(config('filesystems.disks.public.secret')),
            'endpoint' => $endpoint !== '' ? $this->safeHost($endpoint) : null,
            'region' => config('filesystems.disks.public.region'),
            'public_bucket' => config('filesystems.disks.public.bucket'),
            'private_bucket' => config('filesystems.disks.private_uploads.bucket'),
            'public_url' => $publicUrl !== '' ? $publicUrl : null,
        ];
    }

    private function probe(string $diskName, bool $public): array
    {
        $path = 'viti-health/'.Str::uuid().'.txt';
        $disk = Storage::disk($diskName);

        try {
            $written = $disk->put($path, 'VITI storage OK '.now()->toIso8601String());
            if (!$written || !$disk->exists($path)) {
                return [
                    'ok' => false,
                    'driver' => config("filesystems.disks.$diskName.driver"),
                    'message' => 'No se pudo confirmar la escritura en el almacenamiento.',
                    'diagnostico' => 'La conexión respondió, pero no confirmó la creación del objeto de prueba.',
                ];
            }

            $url = $public ? $disk->url($path) : null;
            $disk->delete($path);

            return [
                'ok' => true,
                'driver' => config("filesystems.disks.$diskName.driver"),
                'url_base' => $public ? config("filesystems.disks.$diskName.url") : null,
                'prueba_url' => $url,
                'message' => $public
                    ? 'El almacenamiento público puede escribir, leer y generar URL.'
                    : 'El almacenamiento privado puede escribir y leer correctamente.',
            ];
        } catch (Throwable $e) {
            report($e);

            $awsCode = method_exists($e, 'getAwsErrorCode') ? $e->getAwsErrorCode() : null;
            $raw = trim((string) $e->getMessage());
            $safe = $this->sanitizeMessage($raw);

            return [
                'ok' => false,
                'driver' => config("filesystems.disks.$diskName.driver"),
                'message' => 'No pudimos conectar con el almacenamiento configurado.',
                'diagnostico' => $awsCode ?: ($safe ?: class_basename($e)),
            ];
        }
    }

    private function safeHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ?: $url;
    }

    private function sanitizeMessage(string $message): string
    {
        if ($message === '') return '';

        $message = preg_replace('/(AWSAccessKeyId|Signature|Credential|Secret|Authorization)=?[^\s,&]*/i', '$1=[oculto]', $message) ?? $message;
        $message = preg_replace('/https?:\/\/[^\s]+/i', '[url]', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return mb_substr($message, 0, 220);
    }
}
