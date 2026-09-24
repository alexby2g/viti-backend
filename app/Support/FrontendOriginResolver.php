<?php

namespace App\Support;

final class FrontendOriginResolver
{
    /**
     * @return list<string>
     */
    public static function origins(?string $frontendUrls, ?string $frontendAppUrl = null): array
    {
        return self::unique([
            ...self::csv($frontendUrls),
            ...self::csv($frontendAppUrl),
        ]);
    }

    /**
     * Patrones extra para despliegues de preview (por ejemplo los dominios
     * efímeros de Vercel). Se configuran con FRONTEND_URL_PATTERNS y son
     * opcionales: si la variable está vacía no cambia nada.
     *
     * @return list<string>
     */
    public static function patterns(?string $patterns): array
    {
        return self::csv($patterns);
    }

    /**
     * Dominios "stateful" para Sanctum, incluyendo comodines para los
     * subdominios de preview cuando están configurados.
     *
     * @param  list<string>  $frontendOrigins
     * @param  list<string>  $fallbackDomains
     * @return list<string>
     */
    public static function statefulDomains(
        ?string $explicitDomains,
        array $frontendOrigins,
        array $fallbackDomains = [],
    ): array {
        $domains = self::csv($explicitDomains);

        if ($domains === []) {
            $domains = $fallbackDomains;
        }

        foreach ($frontendOrigins as $origin) {
            $domain = self::originToDomain($origin);
            if ($domain !== null) {
                $domains[] = $domain;
            }
        }

        return self::unique($domains);
    }

    public static function originToDomain(string $origin): ?string
    {
        $origin = trim($origin);
        if ($origin === '') {
            return null;
        }

        // FRONTEND_URLS usa orígenes completos. Si alguna instalación conserva
        // solamente host[:puerto], lo aceptamos también para no romper compatibilidad.
        $candidate = str_contains($origin, '://') ? $origin : 'https://'.$origin;
        $host = parse_url($candidate, PHP_URL_HOST);
        $port = parse_url($candidate, PHP_URL_PORT);

        if (!is_string($host) || trim($host) === '') {
            return null;
        }

        return strtolower($host).($port ? ':'.$port : '');
    }

    /**
     * @return list<string>
     */
    private static function csv(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $item): string => trim($item), explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        $result = [];
        $seen = [];

        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $key = strtolower($value);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $value;
        }

        return $result;
    }
}
