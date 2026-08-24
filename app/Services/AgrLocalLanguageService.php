<?php

namespace App\Services;

final class AgrLocalLanguageService
{
    /**
     * Extensible local vocabulary for VITI navigation.
     * Add aliases/routes here as new modules appear; no external AI is required.
     */
    private const MODULES = [
        'clients' => [
            'route' => '/empresas',
            'label' => 'clientes',
            'aliases' => ['cliente', 'clientes', 'registro de clientes', 'registros de clientes', 'usuarios clientes'],
        ],
        'companies' => [
            'route' => '/empresas',
            'label' => 'empresas',
            'aliases' => ['empresa', 'empresas', 'negocio', 'negocios', 'compania', 'compañia', 'compañias', 'organizaciones'],
        ],
        'requests' => [
            'route' => '/solicitudes',
            'label' => 'solicitudes',
            'aliases' => ['solicitud', 'solicitudes', 'requerimientos', 'peticiones', 'pedidos de sistema'],
        ],
        'projects' => [
            'route' => '/proyectos',
            'label' => 'proyectos',
            'aliases' => ['proyecto', 'proyectos', 'trabajos', 'iniciativas'],
        ],
        'support' => [
            'route' => '/mantenimientos',
            'label' => 'soporte y mantenimientos',
            'aliases' => ['soporte', 'soportes', 'mantenimiento', 'mantenimientos', 'incidencia tecnica', 'incidencias tecnicas'],
        ],
    ];

    private const NAVIGATION_HINTS = [
        'abrir', 'abre', 'ir', 've', 'vamos', 'entrar', 'entra', 'mostrar', 'muestra', 'mostrarme',
        'quiero ver', 'quiero revisar', 'necesito ver', 'llevame', 'llévame', 'mandame', 'mándame',
        'dirigeme', 'dirígeme', 'llevarme', 'enviame', 'envíame', 'abre el modulo', 'abre el módulo',
        'entra al modulo', 'entra al módulo', 'muestrame donde', 'muéstrame donde', 'donde puedo ver',
        'dónde puedo ver', 'donde reviso', 'dónde reviso', 'quiero entrar', 'quiero ir',
    ];

    private const MUTATING_HINTS = [
        'crear', 'crea', 'registrar', 'registra', 'nuevo', 'nueva', 'agregar', 'agrega', 'eliminar',
        'elimina', 'borrar', 'borra', 'editar', 'edita', 'actualizar', 'actualiza', 'guardar', 'guarda',
    ];

    public function interpret(string $input): ?array
    {
        $text = $this->normalize($input);
        if ($text === '') return null;

        if ($this->matchesAny($text, ['atras', 'atrás', 'retrocede', 'volver', 'regresa', 'vuelve'])) {
            return $this->actionResponse(
                'browser_back',
                'Retrocedo una pantalla.',
                ['action' => ['type' => 'browser_back']],
                ['style' => 'navigation', 'source' => 'agr_local_language']
            );
        }

        if (!$this->containsNavigationHint($text)) return null;
        if ($this->containsMutatingHint($text)) return null;

        foreach (self::MODULES as $intent => $module) {
            if ($this->containsAlias($text, $module['aliases'])) {
                return $this->actionResponse(
                    'navigate_'.$intent,
                    $this->navigationMessage($intent, $module['label']),
                    ['action' => [
                        'type' => 'navigate',
                        'to' => $module['route'],
                        'target' => $intent,
                        'label' => 'Abrir '.$module['label'],
                    ]],
                    ['style' => 'action_oriented', 'source' => 'agr_local_language', 'parser' => 'v1']
                );
            }
        }

        return null;
    }

    private function navigationMessage(string $intent, string $label): string
    {
        return match ($intent) {
            'clients' => 'Entendido. Te llevo al espacio donde administras clientes.',
            'companies' => 'Entendido. Abro el registro de empresas y negocios.',
            'requests' => 'Perfecto. Te llevo al centro de solicitudes.',
            'projects' => 'Vamos a proyectos. Allí puedes revisar avances y estado.',
            'support' => 'Abro soporte y mantenimientos para revisar las incidencias.',
            default => 'Te llevo a '.$label.'.',
        };
    }

    private function actionResponse(string $intent, string $message, array $data, array $meta): array
    {
        return [
            'intent' => $intent,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
        ];
    }

    private function containsNavigationHint(string $text): bool
    {
        foreach (self::NAVIGATION_HINTS as $hint) {
            if (str_contains($text, $this->normalize($hint))) return true;
        }

        return preg_match('/\b(al|a|del|de)\s+(modulo|módulo|seccion|sección|apartado|area|área)\b/u', $text) === 1
            || preg_match('/\b(lugar|espacio)\s+(donde|para)\b/u', $text) === 1;
    }

    private function containsMutatingHint(string $text): bool
    {
        foreach (self::MUTATING_HINTS as $hint) {
            if (str_contains($text, $this->normalize($hint))) return true;
        }

        return false;
    }

    private function containsAlias(string $text, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            $normalized = $this->normalize($alias);
            if ($normalized !== '' && str_contains($text, $normalized)) return true;
        }

        return false;
    }

    private function matchesAny(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if ($text === $this->normalize($phrase) || str_contains($text, $this->normalize($phrase))) return true;
        }

        return false;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        return preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    }
}
