<?php

namespace App\Services;

use Illuminate\Support\Str;

class AgrRecommendationEngineService
{
    public function recommend(array $decision, array $confidence = [], array $permissions = []): array
    {
        $decisionName = $decision['decision'] ?? 'observe';
        $confidenceLevel = $confidence['level'] ?? 'low';
        $confidenceScore = (int) ($confidence['score'] ?? 0);
        $requiresHuman = true;
        $canAutoExecute = false;

        $action = match (true) {
            $decisionName === 'intervene' && $confidenceLevel === 'high' => 'prepare_priority_action',
            $decisionName === 'review' && in_array($confidenceLevel, ['high', 'medium'], true) => 'prepare_review',
            default => 'observe',
        };

        $title = match ($action) {
            'prepare_priority_action' => 'AGR recomienda intervenir',
            'prepare_review' => 'AGR recomienda revisar',
            default => 'AGR recomienda observar',
        };

        $message = match ($action) {
            'prepare_priority_action' => 'La evidencia combinada justifica priorizar la atención del problema detectado.',
            'prepare_review' => 'Hay evidencia suficiente para revisar el problema, pero conviene confirmar antes de actuar.',
            default => 'La evidencia todavía no justifica una intervención; AGR mantendrá el problema bajo observación.',
        };

        return [
            'id' => 'REC-'.strtoupper(Str::substr(hash('sha256', $action.'|'.$confidenceScore.'|'.microtime(true)), 0, 10)),
            'generated_at' => now()->toIso8601String(),
            'title' => $title,
            'message' => $message,
            'action' => $action,
            'decision' => $decisionName,
            'confidence' => [
                'level' => $confidenceLevel,
                'score' => $confidenceScore,
            ],
            'requires_human_confirmation' => $requiresHuman,
            'safe_to_auto_execute' => $canAutoExecute,
            'permissions' => $permissions,
            'evidence' => [
                'decision_score' => $decision['score'] ?? 0,
                'decision_confidence' => $decision['confidence'] ?? 0,
                'signal_count' => $decision['signal_count'] ?? 0,
            ],
        ];
    }
}
