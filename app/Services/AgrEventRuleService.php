<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class AgrEventRuleService
{
    private const CACHE_KEY = 'agr.event_rules.alerts';
    private const TTL_HOURS = 72;

    public function evaluate(array $events): array
    {
        $alerts = [];

        foreach ($events as $event) {
            $type = $event['type'] ?? '';
            $severity = $event['severity'] ?? 'info';

            if ($severity === 'critical') {
                $alerts[] = $this->alert(
                    'critical_event',
                    'Evento crítico detectado',
                    $event['message'] ?? $event['title'] ?? 'AGR detectó un evento crítico.',
                    $event
                );
            }

            if ($type === 'request_approved' && ($event['context']['project_id'] ?? null) === null) {
                $alerts[] = $this->alert(
                    'approved_request_without_project',
                    'Solicitud aprobada sin proyecto',
                    'Una solicitud fue aprobada pero todavía no tiene proyecto asociado.',
                    $event,
                    'high'
                );
            }

            if ($type === 'job_failed_repeated' || (($event['context']['repeat_count'] ?? 0) >= 3 && $type === 'job_failed')) {
                $alerts[] = $this->alert(
                    'repeated_job_failure',
                    'Trabajo fallando repetidamente',
                    'Un trabajo falló varias veces y requiere revisión.',
                    $event,
                    'high'
                );
            }

            if ($type === 'support_stale') {
                $alerts[] = $this->alert(
                    'stale_support',
                    'Soporte sin actividad',
                    $event['message'] ?? 'Existe una atención técnica sin actividad reciente.',
                    $event,
                    'medium'
                );
            }
        }

        $deduped = [];
        foreach ($alerts as $alert) {
            $deduped[$alert['key'].'|'.($alert['context']['entity_id'] ?? $alert['event_id'] ?? 'global')] = $alert;
        }

        $result = array_values($deduped);
        Cache::put(self::CACHE_KEY, $result, now()->addHours(self::TTL_HOURS));

        return $result;
    }

    public function latest(): array
    {
        return Cache::get(self::CACHE_KEY, []);
    }

    private function alert(string $key, string $title, string $message, array $event, string $severity = 'critical'): array
    {
        return [
            'key' => $key,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'event_id' => $event['id'] ?? null,
            'event_type' => $event['type'] ?? null,
            'context' => $event['context'] ?? [],
            'created_at' => now()->toIso8601String(),
        ];
    }
}
