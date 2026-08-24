<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AgrLearningMemoryService
{
    private const CACHE_KEY = 'agr.learning.memory';
    private const TTL_HOURS = 168;
    private const MAX_RECORDS = 200;

    public function recordDecision(array $decision, array $context = []): array
    {
        $items = Cache::get(self::CACHE_KEY, []);
        $record = [
            'id' => 'LRN-'.strtoupper(Str::substr(hash('sha256', json_encode($decision).microtime(true)), 0, 10)),
            'at' => now()->toIso8601String(),
            'decision_id' => $decision['id'] ?? null,
            'decision' => $decision['decision'] ?? 'observe',
            'score' => $decision['score'] ?? 0,
            'confidence' => $decision['confidence'] ?? 0,
            'recommendation' => $decision['recommendation'] ?? null,
            'outcome' => 'pending',
            'outcome_note' => null,
            'context' => $context,
        ];

        array_unshift($items, $record);
        $items = array_slice($items, 0, self::MAX_RECORDS);
        Cache::put(self::CACHE_KEY, $items, now()->addHours(self::TTL_HOURS));

        return $record;
    }

    public function updateOutcome(string $recordId, string $outcome, ?string $note = null): ?array
    {
        $allowed = ['pending', 'confirmed', 'resolved', 'rejected', 'unknown'];
        if (!in_array($outcome, $allowed, true)) return null;

        $items = Cache::get(self::CACHE_KEY, []);
        foreach ($items as $index => $item) {
            if (($item['id'] ?? null) !== $recordId) continue;

            $item['outcome'] = $outcome;
            $item['outcome_note'] = $note;
            $item['updated_at'] = now()->toIso8601String();
            $items[$index] = $item;
            Cache::put(self::CACHE_KEY, $items, now()->addHours(self::TTL_HOURS));
            return $item;
        }

        return null;
    }

    public function latest(int $limit = 30): array
    {
        return array_slice(Cache::get(self::CACHE_KEY, []), 0, max(1, min($limit, self::MAX_RECORDS)));
    }

    public function patterns(int $limit = 20): array
    {
        $items = $this->latest(self::MAX_RECORDS);
        $groups = [];

        foreach ($items as $item) {
            $key = ($item['decision'] ?? 'observe').'|'.($item['recommendation'] ?? '');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => 'PAT-'.strtoupper(Str::substr(hash('sha256', $key), 0, 8)),
                    'decision' => $item['decision'] ?? 'observe',
                    'recommendation' => $item['recommendation'] ?? null,
                    'count' => 0,
                    'successful_outcomes' => 0,
                    'negative_outcomes' => 0,
                    'pending_outcomes' => 0,
                ];
            }

            $groups[$key]['count']++;
            if (in_array($item['outcome'] ?? 'pending', ['confirmed', 'resolved'], true)) $groups[$key]['successful_outcomes']++;
            elseif (in_array($item['outcome'] ?? 'pending', ['rejected'], true)) $groups[$key]['negative_outcomes']++;
            else $groups[$key]['pending_outcomes']++;
        }

        foreach ($groups as &$group) {
            $resolved = $group['successful_outcomes'] + $group['negative_outcomes'];
            $group['success_rate'] = $resolved > 0 ? round(($group['successful_outcomes'] / $resolved) * 100, 1) : null;
        }
        unset($group);

        return array_slice(array_values($groups), 0, max(1, min($limit, 50)));
    }

    public function summary(): array
    {
        $items = $this->latest(self::MAX_RECORDS);
        $resolved = collect($items)->whereIn('outcome', ['confirmed', 'resolved'])->count();
        $negative = collect($items)->where('outcome', 'rejected')->count();
        return [
            'records' => count($items),
            'resolved_or_confirmed' => $resolved,
            'rejected' => $negative,
            'pending' => collect($items)->where('outcome', 'pending')->count(),
            'patterns' => $this->patterns(10),
            'mode' => 'local_outcome_memory',
        ];
    }
}
