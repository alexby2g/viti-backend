<?php

namespace App\Services;

class AgrExplanationEngineService
{
    public function explain(array $recommendation, array $decision = [], array $confidence = [], array $context = []): array
    {
        $signals = $decision['signals'] ?? ($recommendation['signals'] ?? []);
        $reasons = [];
        foreach (array_slice($signals, 0, 5) as $signal) {
            $source = $signal['source'] ?? 'AGR';
            $message = $signal['message'] ?? 'Señal detectada.';
            $reasons[] = ucfirst(str_replace('_', ' ', $source)).': '.$message;
        }

        $score = (int) ($confidence['score'] ?? $decision['score'] ?? 0);
        $level = $confidence['level'] ?? 'low';
        $action = $recommendation['action'] ?? 'observe';

        $risk = match ($action) {
            'prepare_priority_action', 'intervene' => 'Ignorar esta recomendación podría prolongar el problema o retrasar una operación importante.',
            'prepare_review', 'review' => 'La situación merece revisión antes de ejecutar cualquier cambio.',
            default => 'No hay evidencia suficiente para justificar una intervención.',
        };

        return [
            'title' => 'Por qué AGR recomienda esto',
            'summary' => $recommendation['recommendation'] ?? 'AGR recomienda mantener la situación bajo observación.',
            'confidence' => [
                'score' => $score,
                'level' => $level,
            ],
            'reasons' => $reasons,
            'evidence_count' => count($signals),
            'risk_if_ignored' => $risk,
            'proposed_action' => $action,
            'authorization' => [
                'requires_human_confirmation' => true,
                'safe_to_auto_execute' => false,
            ],
            'context' => $context,
            'mode' => 'local_explainable_rules',
        ];
    }
}
