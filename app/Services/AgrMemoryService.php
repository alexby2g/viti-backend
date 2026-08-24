<?php

namespace App\Services;

use App\Models\Empresa;
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

        if (in_array($response['intent'] ?? '', ['create_client', 'create_company', 'create_request'], true)) {
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
        if ($type === 'create_request') return $this->continueRequest($message, $memory);

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

    private function continueRequest(string $message, array &$memory): array
    {
        $pending = $memory['pending'];
        $data = $pending['data'] ?? [];
        $step = (int) ($pending['step'] ?? 0);
        $text = mb_strtolower(trim($message));

        if ($step === 999 && $this->matches($text, ['si', 'sí', 'confirmar', 'confirmo', 'guardar'])) {
            return [
                'intent' => 'create_request_ready',
                'message' => 'La solicitud está preparada. Revisa el resumen y confirma para registrarla en VITI.',
                'data' => ['action' => ['type' => 'form', 'target' => 'request_create', 'confirm_required' => true, 'draft' => $data]],
                'meta' => ['style' => 'safe_action', 'confirm_required' => true],
            ];
        }

        if ($step === 0) {
            if (isset($data['candidate_companies'])) {
                $index = (int) $text - 1;
                if (isset($data['candidate_companies'][$index])) {
                    $company = $data['candidate_companies'][$index];
                    $data['empresa_id'] = $company['id'];
                    $data['empresa_nombre'] = $company['nombre_comercial'];
                    $data['cliente_id'] = $company['cliente_id'];
                    unset($data['candidate_companies']);
                    $memory['pending'] = ['type' => 'create_request', 'step' => 1, 'data' => $data];
                    return $this->requestCollecting('empresa', 'Perfecto. Tomo '. $company['nombre_comercial'] .'. ¿Cuál será el título de la solicitud?', $data, 1);
                }
            }

            $term = trim($message);
            $companies = Empresa::query()
                ->where(function ($query) use ($term) {
                    $query->where('nombre_comercial', 'like', '%'.$term.'%')
                        ->orWhere('razon_social', 'like', '%'.$term.'%')
                        ->orWhere('codigo', 'like', '%'.$term.'%');
                })
                ->whereNotNull('cliente_id')
                ->limit(5)
                ->get(['id','cliente_id','nombre_comercial','codigo','ciudad']);

            if ($companies->isEmpty()) {
                return $this->requestCollecting('empresa', 'No encontré una empresa con ese nombre. Prueba con el nombre comercial exacto o una parte del nombre.', $data, 0);
            }

            if ($companies->count() > 1) {
                $data['candidate_companies'] = $companies->map(fn ($company) => $company->only(['id','cliente_id','nombre_comercial','codigo','ciudad']))->values()->all();
                $lines = [];
                foreach ($data['candidate_companies'] as $index => $company) $lines[] = ($index + 1).'. '.$company['nombre_comercial'].' · '.$company['ciudad'];
                $memory['pending'] = ['type' => 'create_request', 'step' => 0, 'data' => $data];
                return [
                    'intent' => 'create_request_company_selection',
                    'message' => "Encontré varias empresas. Elige una por número:\n" . implode("\n", $lines),
                    'data' => ['results' => $data['candidate_companies']],
                    'meta' => ['style' => 'selection', 'source' => 'viti_local'],
                ];
            }

            $company = $companies->first();
            $data['empresa_id'] = $company->id;
            $data['empresa_nombre'] = $company->nombre_comercial;
            $data['cliente_id'] = $company->cliente_id;
            $memory['pending'] = ['type' => 'create_request', 'step' => 1, 'data' => $data];
            return $this->requestCollecting('empresa', 'Perfecto. Trabajaremos con '.$company->nombre_comercial.'. ¿Cuál será el título de la solicitud?', $data, 1);
        }

        $fields = ['titulo','resumen','prioridad','fecha_limite_deseada','presupuesto_estimado'];
        $field = $fields[$step - 1] ?? null;
        if (!$field) return null;

        $value = $this->optionalValue($message);
        if ($field === 'prioridad' && !in_array($value, ['baja','normal','alta','urgente'], true)) $value = 'normal';
        if ($field === 'fecha_limite_deseada' && in_array($value, ['sin fecha','ninguna','no'], true)) $value = null;
        if ($field === 'presupuesto_estimado' && in_array($value, ['sin presupuesto','ninguno','no'], true)) $value = null;
        $data[$field] = $value;
        $nextStep = $step + 1;

        if ($nextStep > count($fields)) {
            $memory['pending'] = ['type' => 'create_request', 'step' => 999, 'data' => $data];
            return [
                'intent' => 'create_request_ready',
                'message' => 'Listo. Ya tengo la solicitud de '.$data['empresa_nombre'].' preparada. Revisa el resumen y confirma para registrarla.',
                'data' => ['action' => ['type' => 'form', 'target' => 'request_create', 'confirm_required' => true, 'draft' => $data]],
                'meta' => ['style' => 'safe_action', 'confirm_required' => true],
            ];
        }

        $memory['pending'] = ['type' => 'create_request', 'step' => $nextStep, 'data' => $data];
        return $this->requestCollecting($field, $this->requestQuestion($fields[$nextStep - 1] ?? null), $data, $nextStep);
    }

    private function requestCollecting(string $field, string $message, array $data, int $step): array
    {
        return [
            'intent' => 'create_request_collecting',
            'message' => $message,
            'data' => ['field' => $field, 'step' => $step, 'draft' => $data],
            'meta' => ['style' => 'conversational_form', 'source' => 'agr_memory'],
        ];
    }

    private function requestQuestion(?string $field): string
    {
        return match ($field) {
            'titulo' => '¿Qué título tendrá la solicitud?',
            'resumen' => 'Cuéntame brevemente qué necesita el cliente. Puedes escribir “sin resumen”.',
            'prioridad' => '¿Qué prioridad tendrá: baja, normal, alta o urgente?',
            'fecha_limite_deseada' => '¿Hay una fecha límite deseada? Puedes escribir una fecha o “sin fecha”.',
            'presupuesto_estimado' => '¿Hay un presupuesto estimado? Puedes indicar el monto o “sin presupuesto”.',
            default => '¿Qué dato quieres agregar?',
        };
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

    private function optionalValue(string $message): ?string
    {
        $value = $this->value($message);
        return in_array(mb_strtolower($value), ['sin resumen','ninguno','ninguna','no'], true) ? null : $value;
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
            'create_client_collecting','create_company_collecting','create_request_collecting' => 'Responde con el dato solicitado o escribe “cancelar”.',
            'create_client_ready','create_company_ready','create_request_ready' => 'AGR espera tu revisión y confirmación antes de guardar.',
            default => 'AGR conserva el contexto reciente durante la sesión.',
        };
    }

    private function key(): string { return 'agr.memory.'.(auth()->id() ?: 'guest'); }
}
