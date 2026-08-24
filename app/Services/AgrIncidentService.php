<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AgrIncidentService
{
    private const CACHE_KEY = 'agr.incidents.active';
    private const TTL_HOURS = 168;

    public function fromSnapshot(array $snapshot): array
    {
        $incidents = [];
        $health = $snapshot['system_health'] ?? [];
        $priorities = $snapshot['priorities'] ?? [];
        $recommendations = $snapshot['workflow_recommendations'] ?? [];

        $criticalTechnical = collect($health['anomalies'] ?? [])->whereIn('severity', ['critical', 'high'])->values();
        if ($criticalTechnical->isNotEmpty()) {
            $incidents[] = $this->incident('technical_integrity', 'Salud técnica de VITI', $criticalTechnical->pluck('message')->values()->all(), $criticalTechnical->pluck('key')->values()->all(), $criticalTechnical->contains('severity', 'critical') ? 'critical' : 'high', 'technical');
        }

        $operational = collect($priorities)->filter(fn ($item) => in_array($item['key'] ?? '', ['pending_requests', 'support_open', 'payments_attention', 'delivery_capacity'], true));
        $workflow = collect($recommendations);
        if ($operational->isNotEmpty() || $workflow->isNotEmpty()) {
            $signals = array_merge($operational->pluck('message')->values()->all(), $workflow->pluck('message')->values()->all());
            $keys = array_merge($operational->pluck('key')->values()->all(), $workflow->pluck('key')->values()->all());
            $severity = $operational->contains('severity', 'high') || $workflow->contains('severity', 'high') ? 'high' : 'medium';
            $incidents[] = $this->incident('operational_flow', 'Atención operativa acumulada', $signals, $keys, $severity, 'operational');
        }

        return $this->sync($incidents);
    }

    public function active(): array
    {
        return array_values(array_filter(Cache::get(self::CACHE_KEY, []), fn ($item) => !in_array($item['status'] ?? 'closed', ['resolved', 'closed'], true)));
    }

    public function sync(array $detected): array
    {
        $existing = Cache::get(self::CACHE_KEY, []);
        $byKey = collect($existing)->keyBy('key');
        $now = now()->toIso8601String();

        foreach ($detected as $incident) {
            $current = $byKey->get($incident['key']);
            if ($current) {
                $incident['id'] = $current['id'];
                $incident['status'] = in_array($current['status'], ['resolved', 'closed'], true) ? 'detected' : $current['status'];
                $incident['created_at'] = $current['created_at'] ?? $now;
                $incident['updated_at'] = $now;
                $incident['history'] = $current['history'] ?? [];
                $incident['history'][] = ['at' => $now, 'status' => $incident['status'], 'event' => 'detected_again'];
            } else {
                $incident['updated_at'] = $now;
                $incident['history'] = [['at' => $now, 'status' => 'detected', 'event' => 'created']];
            }
            $byKey->put($incident['key'], $incident);
        }

        $items = $byKey->values()->all();
        Cache::put(self::CACHE_KEY, $items, now()->addHours(self::TTL_HOURS));
        return $this->active();
    }

    public function updateStatus(string $incidentId, string $status, ?string $note = null): ?array
    {
        $items = Cache::get(self::CACHE_KEY, []);
        foreach ($items as $index => $item) {
            if (($item['id'] ?? null) !== $incidentId) continue;

            $now = now()->toIso8601String();
            $item['status'] = $status;
            $item['updated_at'] = $now;
            $item['history'] = $item['history'] ?? [];
            $item['history'][] = ['at' => $now, 'status' => $status, 'event' => 'status_changed', 'note' => $note];
            $items[$index] = $item;
            Cache::put(self::CACHE_KEY, $items, now()->addHours(self::TTL_HOURS));
            return $item;
        }

        return null;
    }

    private function incident(string $key, string $title, array $signals, array $keys, string $severity, string $category): array
    {
        $cause = $category === 'technical'
            ? 'Varias señales apuntan a un problema de salud o integridad técnica de VITI.'
            : 'Varias señales operativas están relacionadas y conviene atenderlas como un solo frente.';

        return [
            'id' => 'AGR-'.strtoupper(Str::substr(hash('sha256', $key), 0, 8)),
            'key' => $key,
            'status' => 'detected',
            'severity' => $severity,
            'category' => $category,
            'title' => $title,
            'summary' => count($signals).' señal(es) relacionadas detectadas.',
            'signals' => $signals,
            'signal_keys' => array_values(array_unique($keys)),
            'probable_cause' => $cause,
            'next_state' => 'investigating',
            'requires_confirmation' => true,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
