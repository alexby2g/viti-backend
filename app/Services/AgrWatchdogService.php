<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class AgrWatchdogService
{
    private const HEARTBEAT_KEY = 'agr.watchdog.heartbeat';
    private const STATUS_KEY = 'agr.watchdog.status';

    public function beat(): array
    {
        $heartbeat = now()->toIso8601String();
        Cache::put(self::HEARTBEAT_KEY, $heartbeat, now()->addHours(2));

        return $this->check();
    }

    public function check(): array
    {
        $last = Cache::get(self::HEARTBEAT_KEY);
        $interval = max(1, (int) config('agr.autopilot.interval_minutes', 15));
        $grace = max(5, $interval * 2);
        $ageSeconds = $last ? now()->diffInSeconds($last) : null;

        if ($last === null) {
            $status = 'unknown';
            $message = 'AGR todavía no tiene un heartbeat registrado.';
        } elseif ($ageSeconds > ($grace * 60)) {
            $status = 'critical';
            $message = 'AGR no ha registrado una ronda dentro del margen esperado.';
        } elseif ($ageSeconds > ($interval * 60)) {
            $status = 'warning';
            $message = 'AGR está retrasado respecto a su intervalo habitual de revisión.';
        } else {
            $status = 'healthy';
            $message = 'AGR está activo y su heartbeat está dentro del intervalo esperado.';
        }

        $payload = [
            'status' => $status,
            'message' => $message,
            'last_heartbeat' => $last,
            'age_seconds' => $ageSeconds,
            'expected_interval_minutes' => $interval,
            'grace_minutes' => $grace,
            'checked_at' => now()->toIso8601String(),
        ];

        Cache::put(self::STATUS_KEY, $payload, now()->addHours(6));
        return $payload;
    }

    public function latest(): ?array
    {
        return Cache::get(self::STATUS_KEY);
    }
}
