<?php

namespace App\Services;

use Illuminate\Support\Str;

class AgrDecisionEngineService
{
    public function evaluate(array $snapshot): array
    {
        $signals = [];
        $priorities = $snapshot['priorities'] ?? [];
        $incidents = $snapshot['incidents'] ?? [];
        $health = $snapshot['system_guard']['status'] ?? ($snapshot['system_health']['status'] ?? 'healthy');
        $events = $snapshot['agr_events']['events'] ?? [];
        $rules = $snapshot['agr_events']['critical_alerts'] ?? [];

        foreach ($priorities as $priority) {
            $signals[] = [
                'source' => 'priority',
                'key' => $priority['key'] ?? 'unknown',
                'severity' => $priority['severity'] ?? 'medium',
                'message' => $priority['message'] ?? ($priority['title'] ?? 'Prioridad detectada'),
            ];
        }

        foreach ($incidents as $incident) {
            $signals[] = [
                'source' => 'incident',
                'key' => $incident['key'] ?? ($incident['id'] ?? 'unknown'),
                'severity' => $incident['severity'] ?? 'medium',
                'message' => $incident['title'] ?? 'Incidente detectado',
            ];
        }

        foreach ($rules as $rule) {
            $signals[] = [
                'source' => 'event_rule',
                'key' => $rule['key'] ?? 'event_rule',
                'severity' => $rule['severity'] ?? 'medium',
                'message' => $rule['message'] ?? ($rule['title'] ?? 'Regla activada'),
            ];
        }

        if ($health === 'critical') {
            $signals[] = ['source' => 'system_guard', 'key' => 'system_guard', 'severity' => 'critical', 'message' => 'La salud técnica de VITI está en estado crítico.'];
        } elseif ($health === 'attention') {
            $signals[] = ['source' => 'system_guard', 'key' => 'system_guard', 'severity' => 'high', 'message' => 'La salud técnica de VITI requiere revisión.'];
        }

        $score = 0;
        foreach ($signals as $signal) {
            $score += match ($signal['severity']) {
                'critical' => 40,
                'high' => 20,
                'medium' => 8,
                default => 2,
            };
        }
        $score = min(100, $score);

        $decision = $score >= 70 ? 'intervene' : ($score >= 30 ? 'review' : 'observe');
        $confidence = $signals === [] ? 0 : min(100, 45 + count($signals) * 10);

        $recommendation = match ($decision) {
            'intervene' => 'Concentrar la atención en los incidentes de mayor severidad antes de realizar tareas secundarias.',
            'review' => 'Revisar las señales relacionadas y confirmar la prioridad antes de ejecutar acciones.',
            default => 'Mantener observación; no hay evidencia suficiente para intervenir automáticamente.',
        };

        return [
            'id' => 'DEC-'.strtoupper(Str::substr(hash('sha256', $decision.'|'.$score.'|'.count($signals)), 0, 10)),
            'generated_at' => now()->toIso8601String(),
            'mode' => 'local_rules',
            'decision' => $decision,
            'score' => $score,
            'confidence' => $confidence,
            'signal_count' => count($signals),
            'critical_event_count' => count($rules),
            'recommendation' => $recommendation,
            'signals' => array_slice($signals, 0, 20),
            'safe_to_auto_execute' => false,
            'requires_human_confirmation' => true,
            'note' => 'El motor decide con reglas locales y evidencia observable; no usa IA externa ni ejecuta acciones de negocio por sí solo.',
        ];
    }
}
