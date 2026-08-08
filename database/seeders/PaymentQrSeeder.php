<?php

namespace Database\Seeders;

use App\Models\ConfiguracionPago;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PaymentQrSeeder extends Seeder
{
    public function run(): void
    {
        $config = ConfiguracionPago::query()->where('activo', true)->first();
        if (!$config) return;

        $current = ltrim((string) $config->qr_path, '/');
        if ($current && Storage::disk('public')->exists($current)) return;

        $source = public_path('viti-payment-qr.svg');
        if (!is_file($source)) return;

        $target = 'pagos/qr/viti-payment-qr.svg';

        try {
            Storage::disk('public')->put($target, file_get_contents($source), [
                'visibility' => 'public',
                'ContentType' => 'image/svg+xml',
            ]);
            $config->update(['qr_path' => $target]);
        } catch (Throwable $e) {
            report($e);
            // El endpoint seguirá usando el QR local de respaldo.
        }
    }
}
