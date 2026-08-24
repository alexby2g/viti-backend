<?php

namespace App\Services;

use App\Models\SolicitudSistema;

class AgrProjectConversionService
{
    public function handle(string $input): ?array
    {
        $text = trim($input);
        $normalized = mb_strtolower($text);

        if (!preg_match('/\b(convertir|convierte|pasar)\b.*\b(solicitud|sol)\b/i', $normalized)) {
            return null;
        }

        $term = trim(preg_replace('/^(.*?)\b(convertir|convierte|pasar)\b.*?\b(solicitud|sol)\b\s*/iu', '', $text) ?? '');
        $term = trim(preg_replace('/^(la|el|a|en)\s+/iu', '', $term) ?? '');

        $query = SolicitudSistema::query()->with(['empresa:id,cliente_id,nombre_comercial','cliente:id,nombre']);

        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('codigo', 'like', '%'.$term.'%')
                    ->orWhere('titulo', 'like', '%'.$term.'%')
                    ->orWhereHas('empresa', fn ($e) => $e->where('nombre_comercial', 'like', '%'.$term.'%'));
            });
        }

        $requests = $query->latest('id')->limit(5)->get();

        if ($requests->isEmpty()) {
            return [
                'intent' => 'convert_request_not_found',
                'message' => $term !== ''
                    ? 'No encontré una solicitud relacionada con "'.$term.'". Puedo buscar por código, título o empresa.'
                    : 'Indícame el código, título o empresa de la solicitud que quieres convertir.',
                'data' => [],
                'meta' => ['style' => 'guided', 'source' => 'viti_local'],
            ];
        }

        if ($requests->count() > 1) {
            $results = $requests->map(fn ($request) => [
                'id' => $request->id,
                'codigo' => $request->codigo,
                'titulo' => $request->titulo,
                'empresa' => $request->empresa?->nombre_comercial,
                'estado' => $request->estado,
            ])->values()->all();

            return [
                'intent' => 'convert_request_selection',
                'message' => 'Encontré varias solicitudes. Elige una por número para preparar la conversión.',
                'data' => ['results' => $results],
                'meta' => ['style' => 'selection', 'source' => 'viti_local'],
            ];
        }

        return $this->prepare($requests->first());
    }

    private function prepare(SolicitudSistema $request): array
    {
        if ($request->estado !== 'aprobada') {
            return [
                'intent' => 'convert_request_blocked',
                'message' => 'La solicitud '.$request->codigo.' está en estado '.$request->estado.'. VITI exige que esté aprobada antes de convertirla en proyecto.',
                'data' => [
                    'request' => $request->only(['id','codigo','titulo','estado']),
                    'action' => ['type' => 'navigate', 'to' => '/solicitudes', 'label' => 'Revisar solicitud'],
                ],
                'meta' => ['style' => 'guardrail', 'source' => 'viti_local'],
            ];
        }

        $draft = [
            'solicitud_id' => $request->id,
            'empresa_id' => $request->empresa_id,
            'cliente_id' => $request->cliente_id,
            'nombre' => $request->titulo,
            'descripcion' => $request->resumen,
            'empresa_nombre' => $request->empresa?->nombre_comercial,
            'cliente_nombre' => $request->cliente?->nombre,
            'fase' => 'levantamiento',
            'estado' => 'activo',
            'progreso' => 0,
        ];

        return [
            'intent' => 'convert_request_ready',
            'message' => 'La solicitud '.$request->codigo.' está aprobada. Preparé un borrador de proyecto con sus datos principales; revisa y confirma antes de crearlo.',
            'data' => [
                'request' => $request->only(['id','codigo','titulo','estado']),
                'draft' => $draft,
                'action' => ['type' => 'navigate', 'to' => '/proyectos', 'label' => 'Revisar proyecto'],
            ],
            'meta' => ['style' => 'safe_action', 'confirm_required' => true, 'source' => 'viti_local'],
        ];
    }
}
