<?php

namespace App\Services;

class AgrNaturalLanguageService
{
    public function adapt(string $input): array
    {
        $text = $this->normalize($input);

        if ($text === '') return ['intent' => 'help', 'normalized' => 'ayuda', 'confidence' => 1.0];

        $patterns = [
            'system_scan' => [
                'haz una ronda completa', 'ronda completa', 'revisa viti', 'revisa todo',
                'como esta viti', 'que se rompio', 'que esta mal', 'que esta pasando',
            ],
            'important_only' => [
                'solo lo importante', 'solo lo urgente', 'solo problemas', 'solo lo que necesita atencion',
            ],
            'why_recommendation' => [
                'por que recomiendas esto', 'porque recomiendas esto', 'por que me recomiendas esto',
                'explicame esta recomendacion', 'por que esta prioridad',
            ],
            'decision' => [
                'que deberiamos atender primero', 'que debemos atender primero', 'que hago primero',
                'cual es la prioridad', 'que recomiendas hacer',
            ],
            'activity_while_away' => [
                'que paso mientras no estaba', 'que paso mientras estuve fuera', 'que ocurrio mientras no estaba',
                'que hizo agr mientras no estaba',
            ],
            'context_lookup' => [
                'que esta pasando con ', 'revisa ', 'analiza ', 'cuentame sobre ',
            ],
        ];

        $best = ['intent' => 'unknown', 'normalized' => $text, 'confidence' => 0.0, 'subject' => null];
        foreach ($patterns as $intent => $phrases) {
            foreach ($phrases as $phrase) {
                if ($phrase !== '' && str_contains($text, $phrase)) {
                    $confidence = min(0.98, 0.70 + min(0.25, strlen($phrase) / 100));
                    if ($confidence > $best['confidence']) {
                        $subject = null;
                        if ($intent === 'context_lookup') {
                            $subject = trim(str_replace($phrase, '', $text));
                        }
                        $best = ['intent' => $intent, 'normalized' => $text, 'confidence' => $confidence, 'subject' => $subject ?: null];
                    }
                }
            }
        }

        return $best;
    }

    public function rewriteForAssistant(array $adapted): ?string
    {
        return match ($adapted['intent'] ?? 'unknown') {
            'system_scan' => 'resumen',
            'important_only' => 'resumen',
            'decision' => 'resumen',
            default => null,
        };
    }

    private function normalize(string $input): string
    {
        $text = mb_strtolower(trim($input));
        $text = strtr($text, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return $text;
    }
}
