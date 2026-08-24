<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class AgrMemoryService
{
    private const TTL_HOURS = 12;
    private const MAX_TURNS = 10;

    public function handle(string $message, AgrAssistantService $assistant): array
    {
        $key = $this->key();
        $memory = Cache::get($key, ['turns' => [], 'last_result' => null]);
        $text = mb_strtolower(trim($message));

        if ($this->isRepeat($text) && !empty($memory['last_result'])) {
            $response = $memory['last_result'];
            $response['meta'] = array_merge($response['meta'] ?? [], ['memory' => 'replayed_previous_response']);
            return $this->remember($key, $memory, $message, $response);
        }

        $response = $assistant->handle($message);

        $followUp = $this->resolveFollowUp($text, $memory);
        if ($followUp !== null) {
            $response = $followUp;
        }

        $response['memory'] = [
            'active' => true,
            'turns' => count($memory['turns']) + 1,
            'hint' => $this->hint($response),
        ];

        return $this->remember($key, $memory, $message, $response);
    }

    public function clear(): void
    {
        Cache::forget($this->key());
    }

    public function snapshot(): array
    {
        return Cache::get($this->key(), ['turns' => [], 'last_result' => null]);
    }

    private function resolveFollowUp(string $text, array $memory): ?array
    {
        $last = $memory['last_result'] ?? null;
        if (!$last) return null;

        if ($this->matches($text, ['ese cliente', 'ese usuario', 'el cliente anterior', 'el primero'])) {
            $results = $last['data']['results'] ?? [];
            if ($last['intent'] === 'search_client' && !empty($results)) {
                $client = $results[0];
                return [
                    'intent' => 'select_client',
                    'message' => 'Entendido. Tomo como referencia a '.$client['nombre'].'.',
                    'data' => ['selected' => $client, 'action' => ['type' => 'focus_client', 'id' => $client['id']]],
                    'meta' => ['style' => 'contextual', 'source' => 'agr_memory'],
                ];
            }
        }

        if ($this->matches($text, ['esa empresa', 'esa empresa anterior', 'el negocio anterior', 'la primera empresa'])) {
            $results = $last['data']['results'] ?? [];
            if ($last['intent'] === 'search_company' && !empty($results)) {
                $company = $results[0];
                return [
                    'intent' => 'select_company',
                    'message' => 'Perfecto. Tomo como referencia a '.$company['nombre_comercial'].'.',
                    'data' => ['selected' => $company, 'action' => ['type' => 'focus_company', 'id' => $company['id']],
                    ],
                    'meta' => ['style' => 'contextual', 'source' => 'agr_memory'],
                ];
            }
        }

        return null;
    }

    private function remember(string $key, array $memory, string $message, array $response): array
    {
        $turns = $memory['turns'] ?? [];
        $turns[] = [
            'at' => now()->toIso8601String(),
            'user' => $message,
            'intent' => $response['intent'] ?? 'unknown',
            'assistant' => $response['message'] ?? '',
        ];
        $turns = array_slice($turns, -self::MAX_TURNS);

        Cache::put($key, [
            'turns' => $turns,
            'last_result' => $response,
        ], now()->addHours(self::TTL_HOURS));

        return $response;
    }

    private function isRepeat(string $text): bool
    {
        return $this->matches($text, ['repite', 'repite eso', 'otra vez', 'dime otra vez', 'que dijiste', 'qué dijiste']);
    }

    private function matches(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if ($text === $phrase || str_contains($text, $phrase)) return true;
        }
        return false;
    }

    private function hint(array $response): string
    {
        return match ($response['intent'] ?? 'unknown') {
            'search_client' => 'Puedes decir “ese cliente” o “el primero”.',
            'search_company' => 'Puedes decir “esa empresa” o “la primera empresa”.',
            'select_client', 'select_company' => 'AGR mantiene esta referencia durante la sesión.',
            default => 'AGR conserva el contexto reciente durante la sesión.',
        };
    }

    private function key(): string
    {
        return 'agr.memory.'.(auth()->id() ?: 'guest');
    }
}
