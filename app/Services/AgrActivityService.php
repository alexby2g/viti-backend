<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class AgrActivityService
{
    private const CACHE_KEY = 'agr.activity.log';
    private const MAX_ENTRIES = 50;
    private const TTL_HOURS = 72;

    public function record(string $type, string $title, string $message, array $context = []): array
    {
        $entry = [
            'id' => (string) str()->uuid(),
            'at' => now()->toIso8601String(),
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'context' => $context,
        ];

        $items = Cache::get(self::CACHE_KEY, []);
        array_unshift($items, $entry);
        Cache::put(self::CACHE_KEY, array_slice($items, 0, self::MAX_ENTRIES), now()->addHours(self::TTL_HOURS));

        return $entry;
    }

    public function latest(int $limit = 20): array
    {
        return array_slice(Cache::get(self::CACHE_KEY, []), 0, max(1, min($limit, self::MAX_ENTRIES)));
    }

    public function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
