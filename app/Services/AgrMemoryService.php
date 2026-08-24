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
        $workflow = app(AgrActionWorkflowService::class);

        if ($this->isCancel($text)) {
            $workflow->clear();
            return $this->remember($key, $memory, $message, [
                'intent' => 'workflow_cancelled',
                'message' => 'Entendido. Dejé sin efecto la operación pendiente. No se modificó ningún dato.',
                'data' => [],
                'meta' => ['style' => 'safe_action', 'source' => 'agr_memory'],
            ]);
        }

        $activeWorkflow = $workflow->current();
        if ($activeWorkflow && ($activeWorkflow['status'] ?? null) === 'collecting') {
            $response = $this->continueWorkflow($text, $activeWorkflow, $workflow);
            if ($response !== null) return $this->remember($key, $memory, $message, $response);
        }

        if ($this->isRepeat($text) && !empty($memory['last_result'])) {
            $response = $memory['last_result'];
            $response['meta'] = array_merge($response['meta'] ?? [], ['memory' => 'replayed_previous_response']);
            return $this->remember($key, $memory, $message, $response);
        }

        $response = $assistant->handle($message);

        if (($response['intent'] ?? '') === 'create_client') {
            $workflow->start('create_client');
            $response['data']['workflow'] = ['status' => 'collecting', 'step' => 1, 'next' => 'nombre'];
            $response['message'] = 'Perfecto. Empezaremos por lo esencial. ¿Cuál es el nombre completo del cliente?';
        }

        $followUp = $this->resolveFollowUp($text, $memory);
        if ($followUp !== null) $response = $followUp;

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
        app(AgrActionWorkflowService::class)->clear();
    }

    public function snapshot(): array
    {
        return Cache::get($this->key(), ['turns' => [], 'last_result' => null]);
    }

    private function continueWorkflow(string $text, array $workflow, AgrActionWorkflowService $state): ?array
    {
        if (($workflow['intent'] ?? '') !== 'create_client') return null;

        $step = (int) ($workflow['step'] ?? 1);
        $data = $workflow['data'] ?? [];

        if ($step === 1) {
            $data['nombre'] = trim($text);
            $state->update($data, 2);
            return $this->workflowResponse('nombre', '¿Qué número de teléfono tendrá '.$data['nombre'].'?', $data, 2);
        }
        if ($step === 2) {
            $data['telefono'] = trim($text);
            $state->update($data, 3);
            return $this->workflowResponse('telefono', '¿Quieres guardar también un número de WhatsApp? Puedes responder “igual” o indicar otro.', $data, 3);
        }
        if ($step === 3) {
            $data['whatsapp'] = $text === 'igual' ? ($data['telefono'] ?? '') : trim($text);
            $state->update($data, 4);
            return $this->workflowResponse('whatsapp', '¿Cuál es el correo del cliente? Si no tiene, escribe “sin correo”.', $data, 4);
        }
        if ($step === 4) {
            $data['correo'] = $text === 'sin correo' ? null : trim($text);
            $state->update($data, 5);
            return $this->workflowResponse('correo', '¿En qué ciudad está el cliente?', $data, 5);
        }
        if ($step === 5) {
            $data['ciudad'] = trim($text);
            $state->update($data, 6);
            return $this->workflowResponse('ciudad', '¿Quieres agregar una dirección? Escribe la dirección o “sin dirección”.', $data, 6);
        }
        if ($step === 6) {
            $data['direccion'] = $text === 'sin dirección' ? null : trim($text);
            $state->update($data, 7);
            return $this->workflowResponse('direccion', 'Último paso: ¿alguna observación para este cliente? Escribe “ninguna” si no hay.', $data, 7);
        }
        if ($step === 7) {
            $data['observaciones'] = $text === 'ninguna' ? null : trim($text);
            $workflow = $state->update($data, 8);
            $state->ready();
            return [
                'intent' => 'create_client_ready',
                'message' => 'Listo. Ya tengo todos los datos. Revisa el registro y confirma cuando estés seguro de guardarlo.',
                'data' => [
                    'action' => ['type' => 'form', 'target' => 'client_create', 'confirm_required' => true],
                    'draft' => $workflow['data'],
                ],
                'meta' => ['style' => 'safe_action', 'source' => 'agr_workflow', 'confirm_required' => true],
            ];
        }
        return null;
    }

    private function workflowResponse(string $field, string $message, array $data, int $step): array
    {
        return [
            'intent' => 'create_client_workflow',
            'message' => $message,
            'data' => ['field' => $field, 'step' => $step, 'draft' => $data],
            'meta' => ['style' => 'conversational_action', 'source' => 'agr_workflow'],
        ];
    }

    private function isCancel(string $text): bool
    {
        return $this->matches($text, ['cancelar', 'cancela', 'olvidalo', 'olvídalo', 'detener']);
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
        Cache::put($key,['turns'=>array_slice($turns,-self::MAX_TURNS),'last_result'=>$response],now()->addHours(self::TTL_HOURS));
        return $response;
    }

    private function isRepeat(string $text): bool
    {
        return $this->matches($text, ['repite', 'repite eso', 'otra vez', 'dime otra vez', 'que dijiste', 'qué dijiste']);
    }

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
            'select_client', 'select_company' => 'AGR mantiene esta referencia durante la sesión.',
            'create_client_workflow' => 'Puedes cancelar en cualquier momento escribiendo “cancelar”.',
            'create_client_ready' => 'La escritura sigue bloqueada hasta tu confirmación.',
            default => 'AGR conserva el contexto reciente durante la sesión.',
        };
    }

    private function key(): string
    {
        return 'agr.memory.'.(auth()->id() ?: 'guest');
    }
}
