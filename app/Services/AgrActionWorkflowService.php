<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class AgrActionWorkflowService
{
    private const TTL_HOURS = 2;

    public function start(string $intent): array
    {
        $workflow = [
            'intent' => $intent,
            'status' => 'collecting',
            'step' => 0,
            'data' => [],
            'updated_at' => now()->toIso8601String(),
        ];

        $this->store($workflow);

        return $workflow;
    }

    public function current(): ?array
    {
        return Cache::get($this->key());
    }

    public function clear(): void
    {
        Cache::forget($this->key());
    }

    public function update(array $data, int $step): array
    {
        $workflow = $this->current() ?? $this->start('create_client');
        $workflow['data'] = array_merge($workflow['data'] ?? [], $data);
        $workflow['step'] = $step;
        $workflow['updated_at'] = now()->toIso8601String();
        $this->store($workflow);

        return $workflow;
    }

    public function ready(): ?array
    {
        $workflow = $this->current();
        if (!$workflow) return null;
        $workflow['status'] = 'ready_for_confirmation';
        $workflow['updated_at'] = now()->toIso8601String();
        $this->store($workflow);
        return $workflow;
    }

    private function store(array $workflow): void
    {
        Cache::put($this->key(), $workflow, now()->addHours(self::TTL_HOURS));
    }

    private function key(): string
    {
        return 'agr.workflow.'.(auth()->id() ?: 'guest');
    }
}
