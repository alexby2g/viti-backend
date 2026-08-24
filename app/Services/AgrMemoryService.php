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
        $memory = Cache::get($key, ['turns' => [], 'last_result' => null, 'pending' => null]);
        $text = mb_strtolower(trim($message));

        if ($this->matches($text, ['cancelar', 'cancelar operacion', 'cancela'])) {
            if ($memory['pending']) {
                $memory['pending'] = null;
                Cache::put($key, $memory, now()->addHours(self::TTL_HOURS));
                return $this->remember($key, $memory, $message, [
                    'intent' => 'action_cancelled',
                    'message' => 'Operación cancelada. No se modificó ningún dato.',
                    'data' => [],
                    'meta' => ['style' => 'safe_action'],
                ]);
            }
        }

        if ($this->isRepeat($text) && !empty($memory['last_result'])) {
            $response = $memory['last_result'];
            $response['meta'] = array_merge($response['meta'] ?? [], ['memory' => 'replayed_previous_response']);
            return $this->remember($key, $memory, $message, $response);
        }

        if ($memory['pending']) {
            $followUp = $this->continuePending($message, $memory);
            if ($followUp !== null) return $this->remember($key, $memory, $message, $followUp);
        }

        $response = $assistant->handle($message);

        if (in_array($response['intent'] ?? '', ['create_client', 'create_company'], true)) {
            $memory['pending'] = ['type' => $response['intent'], 'step' => 0, 'data' => []];
            $response['memory'] = ['active' => true, 'pending_action' => true, 'hint' => 'AGR recopilará los datos paso a paso.'];
        } else {
            $followUp = $this->resolveFollowUp($text, $memory);
            if ($followUp !== null) $response = $followUp;
        }

        $response['memory'] = array_merge($response['memory'] ?? [], [
            'active' => true,
            'turns' => count($memory['turns']) + 1,
            'pending_action' => (bool) $memory['pending'],
            'hint' => $this->hint($response),
        ]);

        return $this->remember($key, $memory, $message, $response);
    }

    public function clear(): void { Cache::forget($this->key()); }

    public function snapshot(): array
    {
        return Cache::get($this->key(), ['turns' => [], 'last_result' => null, 'pending' => null]);
    }

    public function clearPending(): void
    {
        $key = $this->key();
        $memory = $this->snapshot();
        $memory['pending'] = null;
        Cache::put($key, $memory, now()->addHours(self::TTL_HOURS));
    }

    private function continuePending(string $message, array &$memory): ?array
    {
        $pending = $memory['pending'];
        $type = $pending['type'];
        $data = $pending['data'] ?? [];
        $text = mb_strtolower(trim($message));
        $fields = $this->fields($type);
        $step = (int) ($pending['step'] ?? 0);

        if ($step === 999 && $this->matches($text, ['si', 'sí', 'confirmar', 'confirmo', 'guardar'])) {
            return [
                'intent' => $type.'_ready',
                'message' => $this->confirmationMessage($type, $data),
                'data' => ['action' => ['type' => 'form', 'target' => $type === 'create_client' ? 'client_create' : 'company_create', 'confirm_required' => true, 'draft' => $data]],
                'meta' => ['style' => 'safe_action', 'confirm_required' => true],
            ];
        }

        if ($step >= count($fields)) return null;

        $field = $fields[$step];
        $data[$field] = $this->value($message);
        $step++;

        if ($step >= count($fields)) {
            $memory['pending'] = ['type' => $type, 'step' => 999, 'data' => $data];
            return [
                'intent' => $type.'_ready',
                'message' => $this->confirmationMessage($type, $data),
                'data' => ['action' => ['type' => 'form', 'target' => $type === 'create_client' ? 'client_create' : 'company_create', 'confirm_required' => true, 'draft' => $data]],
                'meta' => ['style' => 'safe_action', 'confirm_required' => true],
            ];
        }

        $memory['pending'] = ['type' => $type, 'step' => $step, 'data' => $data];
        return [
            'intent' => $type.'_collecting',
            'message' => $this->nextQuestion($type, $step),
            'data' => ['draft' => $data, 'next_field' => $fields[$step] ?? null],
            'meta' => ['style' => 'conversational_form'],
        ];
    }

    private function fields(string $type): array
    {
        return $type === 'create_client'
            ? ['nombre','telefono','whatsapp','correo','ciudad','direccion']
            : ['nombre_comercial','razon_social','actividad','telefono','whatsapp','ciudad','direccion'];
    }

    private function nextQuestion(string $type, int $step): string
    {
        return match ($this->fields($type)[$step] ?? null) {
            'nombre' => 'Perfecto. ¿Cuál es el nombre completo del cliente?',
            'telefono' => '¿Qué número de teléfono tendrá?',
            'whatsapp' => '¿Deseas registrar un número de WhatsApp?',
            'correo' => '¿Cuál es el correo electrónico?',
            'ciudad' => '¿En qué ciudad está?',
            'direccion' => '¿Cuál es la dirección?',
            'nombre_comercial' => 'Perfecto. ¿Cuál será el nombre comercial?',
            'razon_social' => '¿Cuál es la razón social? Si no la tienes, puedes escribir “sin razón social”.',
            'actividad' => '¿A qué actividad o rubro se dedica?',
            default => '¿Hay algún dato adicional que quieras incluir?',
        };
    }

    private function confirmationMessage(string $type, array $data): string
    {
        if ($type === 'create_client') return 'Tengo preparado el cliente '.($data['nombre'] ?? '').'. Revisa los datos y confirma el registro en el formulario.';
        return 'Tengo preparada la empresa '.($data['nombre_comercial'] ?? '').'. Revisa los datos y confirma el registro en el formulario.';
    }

    private function value(string $message): string
    {
        return trim(preg_replace('/^\s*(si|sí|igual|correcto|ok|es)\s*[:,-]?\s*/iu', '', $message) ?? $message);
    }

    private function resolveFollowUp(string $text, array $memory): ?array
    {
        $last = $memory['last_result'] ?? null;
        if (!$last) return null;
        if ($this->matches($text, ['ese cliente', 'ese usuario', 'el cliente anterior', 'el primero'])) {
            $results = $last['data']['results'] ?? [];
            if (($last['intent'] ?? '') === 'search_client' && !empty($results)) {
                $client = $results[0];
                return ['intent'=>'select_client','message'=>'Entendido. Tomo como referencia a '.$client['nombre'].'.','data'=>['selected'=>$client,'action'=>['type'=>'focus_client','id'=>$client['id']]],'meta'=>['style'=>'contextual','source'=>'agr_memory']];
            }
        }
        if ($this->matches($text, ['esa empresa', 'esa empresa anterior', 'el negocio anterior', 'la primera empresa'])) {
            $results = $last['data']['results'] ?? [];
            if (($last['intent'] ?? '') === 'search_company' && !empty($results)) {
                $company = $results[0];
                return ['intent'=>'select_company','message'=>'Perfecto. Tomo como referencia a '.$company['nombre_comercial'].'.','data'=>['selected'=>$company,'action'=>['type'=>'focus_company','id'=>$company['id']]],'meta'=>['style'=>'contextual','source'=>'agr_memory']];
            }
        }
        return null;
    }

    private function remember(string $key, array $memory, string $message, array $response): array
    {
        $turns = $memory['turns'] ?? [];
        $turns[] = ['at'=>now()->toIso8601String(),'user'=>$message,'intent'=>$response['intent'] ?? 'unknown','assistant'=>$response['message'] ?? ''];
        $memory['turns'] = array_slice($turns, -self::MAX_TURNS);
        $memory['last_result'] = $response;
        Cache::put($key, $memory, now()->addHours(self::TTL_HOURS));
        return $response;
    }

    private function isRepeat(string $text): bool { return $this->matches($text, ['repite','repite eso','otra vez','dime otra vez','que dijiste','qué dijiste']); }

    private function matches(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) if ($text === $phrase || str_contains($text, $phrase)) return true;
        return false;
    }

    private function hint(array $response): string
    {
        return match ($response['intent'] ?? 'unknown') {
            'search_client' => 'Puedes decir “ese cliente” o “el primero”.',
            'search_company' => 'Puedes decir “esa empresa” o “la primera empresa”.',
            'create_client_collecting','create_company_collecting' => 'Responde con el dato solicitado o escribe “cancelar”.',
            'create_client_ready','create_company_ready' => 'AGR espera tu revisión y confirmación antes de guardar.',
            default => 'AGR conserva el contexto reciente durante la sesión.',
        };
    }

    private function key(): string { return 'agr.memory.'.(auth()->id() ?: 'guest'); }
}
