<?php

namespace App\Services;

use Illuminate\Support\Str;

class AgrConfidenceEngineService
{
    public function evaluate(array $decision, array $patterns = []): array
    {
        $recommendation = (string) ($decision['recommendation'] ?? '');
        $matched = collect($patterns)->first(function (array $pattern) use ($decision, $recommendation): bool {
            return ($pattern['decision'] ?? null) === ($decision['decision'] ?? null)
                && ($pattern['recommendation'] ?? '') === $recommendation;
        });

        $cases = (int) ($matched['count'] ?? 0);
        $successRate = $matched['success_rate'] ?? null;
        $base = (int) ($decision['confidence'] ?? 0);
        $score = $base;
        $evidence = [];

        if ($cases >= 10) {
            $score += 20;
            $evidence[] = '10 o más casos históricos similares';
        } elseif ($cases >= 5) {
            $score += 10;
            $evidence[] = '5 o más casos históricos similares';
        } elseif ($cases > 0) {
            $evidence[] = $cases.' caso(s) histórico(s) similar(es)';
        } else {
            $evidence[] = 'sin historial suficiente para el patrón';
        }

        if (is_numeric($successRate)) {
            if ($successRate >= 85) {
                $score += 20;
                $evidence[] = 'éxito histórico >= 85%';
            } elseif ($successRate >= 70) {
                $score += 10;
                $evidence[] = 'éxito histórico >= 70%';
            } elseif ($successRate < 50) {
                $score -= 15;
                $evidence[] = 'éxito histórico < 50%';
            }
        }

        $score = max(0, min(100, $score));
        $level = $score >= 80 ? 'high' : ($score >= 50 ? 'medium' : 'low');
        $behavior = match ($level) {
            'high' => 'recommend',
            'medium' => 'recommend_with_review',
            default => 'observe_only',
        };

        return [
            'id' => 'CONF-'.strtoupper(Str::substr(hash('sha256', ($decision['id'] ?? '').'|'.$score), 0, 10)),
            'generated_at' => now()->toIso8601String(),
            'score' => $score,
            'level' => $level,
            'behavior' => $behavior,
            'matched_pattern' => $matched['key'] ?? null,
            'historical_cases' => $cases,
            'historical_success_rate' => $successRate,
            'evidence' => $evidence,
            'safe_to_auto_execute' => false,
            'requires_human_confirmation' => true,
        ];
    }
}
