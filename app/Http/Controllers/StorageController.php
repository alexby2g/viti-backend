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
            ],
        ]);
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

            return [
                'ok' => false,
                'driver' => config("filesystems.disks.$diskName.driver"),
                'message' => 'No pudimos conectar con el almacenamiento configurado.',
            ];
        }
    }
}
