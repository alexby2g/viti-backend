<?php

namespace App\Services;

use Illuminate\Support\Str;

class AgrIncidentService
{
    public function fromSnapshot(array $snapshot): array
    {
        $incidents = [];
        $health = $snapshot['system_health'] ?? [];
        $priorities = $snapshot['priorities'] ?? [];
        $recommendations = $snapshot['workflow_recommendations'] ?? [];

        $criticalTechnical = collect($health['anomalies'] ?? [])->whereIn('severity', ['critical', 'high'])->values();
        if ($criticalTechnical->isNotEmpty()) {
            $incidents[] = $this->incident(
                'technical_integrity',
                'Salud técnica de VITI',
                $criticalTechnical->pluck('message')->values()->all(),
                $criticalTechnical->pluck('key')->values()->all(),
                $criticalTechnical->contains('severity', 'critical') ? 'critical' : 'high',
                'technical'
            );
        }

        $operational = collect($priorities)->filter(fn ($item) => in_array($item['key'] ?? '', ['pending_requests', 'support_open', 'payments_attention', 'delivery_capacity'], true));
        $workflow = collect($recommendations);
        if ($operational->isNotEmpty() || $workflow->isNotEmpty()) {
            $signals = $operational->pluck('message')->values()->all();
            $signals = array_merge($signals, $workflow->pluck('message')->values()->all());
            $keys = array_merge($operational->pluck('key')->values()->all(), $workflow->pluck('key')->values()->all());
            $severity = $operational->contains('severity', 'high') || $workflow->contains('severity', 'high') ? 'high' : 'medium';
            $incidents[] = $this->incident(
                'operational_flow',
                'Atención operativa acumulada',
                $signals,
                $keys,
                $severity,
                'operational'
            );
        }

        return $incidents;
    }

    private function incident(string $key, string $title, array $signals, array $keys, string $severity, string $category): array
    {
        $cause = $category === 'technical'
            ? 'Varias señales apuntan a un problema de salud o integridad técnica de VITI.'
            : 'Varias señales operativas están relacionadas y conviene atenderlas como un solo frente.';

        return [
            'id' => 'AGR-'.strtoupper(Str::substr(hash('sha256', $key.'|'.implode('|', $keys)), 0, 8)),
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
        ];
    }
}
