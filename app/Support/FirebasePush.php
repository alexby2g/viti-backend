<?php

namespace App\Support;

use App\Models\PushDevice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FirebasePush
{
    public static function sendToUsers(array $userIds, string $title, string $body, array $data = []): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) return;

        $projectId = trim((string) env('FIREBASE_PROJECT_ID', ''));
        $serviceAccount = self::serviceAccount();
        if ($projectId === '' || !$serviceAccount) return;

        try {
            $accessToken = self::accessToken($serviceAccount);
            if ($accessToken === '') return;

            $devices = PushDevice::query()
                ->whereIn('usuario_id', $userIds)
                ->where('activo', true)
                ->get();

            foreach ($devices as $device) {
                $payloadData = [];
                foreach ($data as $key => $value) {
                    $payloadData[(string) $key] = is_scalar($value) || $value === null
                        ? (string) ($value ?? '')
                        : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $payloadData['title'] = $title;
                $payloadData['body'] = $body;

                $response = Http::withToken($accessToken)
                    ->acceptJson()
                    ->timeout(12)
                    ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                        'message' => [
                            'token' => $device->token,
                            'data' => $payloadData,
                            'android' => [
                                'priority' => 'HIGH',
                                'ttl' => '86400s',
                            ],
                        ],
                    ]);

                if ($response->successful()) continue;

                $text = $response->body();
                if (str_contains($text, 'UNREGISTERED') || str_contains($text, 'registration-token-not-registered')) {
                    $device->update(['activo' => false]);
                }

                Log::warning('FCM no pudo entregar una notificación.', [
                    'usuario_id' => $device->usuario_id,
                    'status' => $response->status(),
                    'respuesta' => str($text)->limit(500)->toString(),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar la notificación FCM.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function serviceAccount(): ?array
    {
        $raw = trim((string) env('FIREBASE_SERVICE_ACCOUNT_JSON', ''));
        if ($raw === '') return null;

        if (!str_starts_with($raw, '{')) {
            $decoded = base64_decode($raw, true);
            if ($decoded !== false) $raw = $decoded;
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) return null;
        return $json;
    }

    private static function accessToken(array $serviceAccount): string
    {
        return Cache::remember('firebase_http_v1_access_token', now()->addMinutes(50), function () use ($serviceAccount): string {
            $now = time();
            $header = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = self::base64Url(json_encode([
                'iss' => $serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            $unsigned = $header.'.'.$claims;
            $signature = '';

            $key = openssl_pkey_get_private($serviceAccount['private_key']);
            if (!$key || !openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) return '';

            $jwt = $unsigned.'.'.self::base64Url($signature);
            $response = Http::asForm()->timeout(12)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (!$response->successful()) {
                Log::warning('Firebase no entregó un access token.', ['status' => $response->status()]);
                return '';
            }

            return (string) $response->json('access_token', '');
        });
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
