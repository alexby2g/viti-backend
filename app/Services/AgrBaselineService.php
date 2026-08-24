<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class AgrBaselineService
{
    private const CACHE_KEY = 'agr.baseline.snapshots';
    private const MAX_SNAPSHOTS = 48;
    private const TTL_HOURS = 72;

    public function analyze(array $metrics): array
    {
        $history = Cache::get(self::CACHE_KEY, []);
        $previous = $history[0] ?? null;
        $anomalies = [];

        if ($previous) {
            $rules = [
                'requests_pending' => ['label' => 'solicitudes pendientes', 'min_change' => 5, 'percent' => 0.60],
                'support_open' => ['label' => 'soportes abiertos', 'min_change' => 4, 'percent' => 0.75],
                'projects_active' => ['label' => 'proyectos activos', 'min_change' => 3, 'percent' => 0.60],
                'payments_attention' => ['label' => 'situaciones de pago', 'min_change' => 3, 'percent' => 0.75],
            ];

            foreach ($rules as $key => $rule) {
                $current = (int) ($metrics[$key] ?? 0);
                $before = (int) ($previous['metrics'][$key] ?? 0);
                $change = $current - $before;
                $rate = $before > 0 ? $change / $before : ($current > 0 ? 1 : 0);

                if ($change >= $rule['min_change'] && $rate >= $rule['percent']) {
                    $anomalies[] = [
                        'key' => 'baseline_'.$key,
                        'severity' => $change >= ($rule['min_change'] * 2) ? 'high' : 'medium',
                        'title' => 'Cambio anormal en '.$rule['label'],
                        'message' => 'Pasó de '.$before.' a '.$current.' desde la última ronda (+' . $change . ').',
                        'metric' => $key,
                        'before' => $before,
                        'current' => $current,
                        'change' => $change,
                        'rate' => round($rate, 2),
                    ];
                }
            }
        }

        $snapshot = [
            'at' => now()->toIso8601String(),
            'metrics' => $metrics,
        ];
        array_unshift($history, $snapshot);
        Cache::put(self::CACHE_KEY, array_slice($history, 0, self::MAX_SNAPSHOTS), now()->addHours(self::TTL_HOURS));

        return [
            'mode' => 'local_baseline',
            'has_history' => (bool) $previous,
            'snapshots' => count($history),
            'anomalies' => $anomalies,
            'summary' => empty($anomalies)
                ? 'No detecté cambios bruscos frente a la ronda anterior.'
                : 'Detecté '.count($anomalies).' cambio(s) fuera de la línea base reciente.',
        ];
    }

    public function latest(): array
    {
        return Cache::get(self::CACHE_KEY, []);
    }
}
