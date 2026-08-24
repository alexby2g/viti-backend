<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AgrEventStreamService
{
    private const CACHE_KEY = 'agr.event_stream';
    private const MAX_EVENTS = 100;
    private const TTL_HOURS = 24;

    public function emit(string $type, string $title, string $message, array $context = [], string $severity = 'info'): array
    {
        $event = [
            'id' => 'EVT-'.strtoupper(Str::substr(hash('sha256', $type.'|'.$title.'|'.microtime(true)), 0, 10)),
            'at' => now()->toIso8601String(),
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'context' => $context,
        ];

        $events = Cache::get(self::CACHE_KEY, []);
        array_unshift($events, $event);
        Cache::put(self::CACHE_KEY, array_slice($events, 0, self::MAX_EVENTS), now()->addHours(self::TTL_HOURS));

        return $event;
    }

    public function latest(int $limit = 30): array
    {
        return array_slice(Cache::get(self::CACHE_KEY, []), 0, max(1, min($limit, self::MAX_EVENTS)));
    }

    public function consumeAndClassify(): array
    {
        $events = $this->latest(50);
        $alerts = [];

        foreach ($events as $event) {
            if (($event['severity'] ?? 'info') === 'critical') {
                $alerts[] = [
                    'key' => 'event_'.$event['type'],
                    'severity' => 'critical',
                    'title' => $event['title'],
                    'message' => $event['message'],
                    'event_id' => $event['id'],
                ];
            }
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'events' => $events,
            'critical_alerts' => array_values($alerts),
            'mode' => 'local_cache_stream',
        ];
    }

    public function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
