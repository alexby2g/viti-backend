<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Proyecto;
use App\Models\SolicitudSistema;
use Illuminate\Support\Str;

class AgrAssistantService
{
    public function handle(string $input): array
    {
        $text = $this->normalize($input);

        if ($text === '') {
            return $this->helpResponse();
        }

        if ($this->matches($text, ['ayuda', 'que puedes hacer', 'comandos', 'opciones'])) {
            return $this->helpResponse();
        }

        if ($this->matches($text, ['cuantos clientes', 'cantidad de clientes', 'total de clientes', 'numero de clientes'])) {
            return [
                'intent' => 'count_clients',
                'message' => 'Actualmente hay '.Cliente::query()->count().' clientes registrados en VITI.',
                'data' => ['count' => Cliente::query()->count()],
            ];
        }

        if ($this->matches($text, ['cuantas empresas', 'cantidad de empresas', 'total de empresas', 'numero de empresas'])) {
            return [
                'intent' => 'count_companies',
                'message' => 'Actualmente hay '.Empresa::query()->count().' empresas registradas en VITI.',
                'data' => ['count' => Empresa::query()->count()],
            ];
        }

        if ($this->matches($text, ['solicitudes pendientes', 'solicitudes sin resolver', 'cuantas solicitudes pendientes'])) {
            $count = SolicitudSistema::query()->whereNotIn('estado', ['completada', 'rechazada', 'cancelada'])->count();

            return [
                'intent' => 'pending_requests',
                'message' => 'Hay '.$count.' solicitudes pendientes.',
                'data' => ['count' => $count],
            ];
        }

        if ($this->matches($text, ['proyectos', 'cuantos proyectos', 'cantidad de proyectos'])) {
            $count = Proyecto::query()->count();

            return [
                'intent' => 'count_projects',
                'message' => 'VITI tiene '.$count.' proyectos registrados.',
                'data' => ['count' => $count],
            ];
        }

        if (preg_match('/^(buscar|busca|encuentra|mostrar|muestrame|muéstrame)\s+(al\s+)?cliente\s+(.+)$/u', $text, $matches)) {
            return $this->searchClient($matches[3]);
        }

        if (str_contains($text, 'cliente') && preg_match('/cliente\s+(.+)/u', $text, $matches)) {
            return $this->searchClient($matches[1]);
        }

        return [
            'intent' => 'unknown',
            'message' => 'Todavía no conozco ese comando. Prueba con: "cuántos clientes tengo", "cuántas empresas tengo", "solicitudes pendientes", "proyectos" o "buscar cliente Juan".',
            'data' => [],
        ];
    }

    private function searchClient(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));

        if ($name === '') {
            return [
                'intent' => 'search_client',
                'message' => 'Necesito el nombre del cliente que quieres buscar.',
                'data' => ['results' => []],
            ];
        }

        $clients = Cliente::query()
            ->where(function ($query) use ($name) {
                $query->where('nombre', 'like', '%'.$name.'%')
                    ->orWhere('telefono', 'like', '%'.$name.'%')
                    ->orWhere('correo', 'like', '%'.$name.'%');
            })
            ->limit(8)
            ->get(['id', 'nombre', 'telefono', 'correo', 'estado']);

        if ($clients->isEmpty()) {
            return [
                'intent' => 'search_client',
                'message' => 'No encontré clientes que coincidan con "'.$name.'".',
                'data' => ['results' => []],
            ];
        }

        return [
            'intent' => 'search_client',
            'message' => 'Encontré '.$clients->count().' resultado(s) para "'.$name.'".',
            'data' => ['results' => $clients->values()->all()],
        ];
    }

    private function helpResponse(): array
    {
        return [
            'intent' => 'help',
            'message' => 'Soy AGR Assistant. Por ahora trabajo con reglas locales y datos reales de VITI, sin API externa.',
            'data' => [
                'commands' => [
                    'Cuántos clientes tengo',
                    'Cuántas empresas tengo',
                    'Solicitudes pendientes',
                    'Cuántos proyectos tengo',
                    'Buscar cliente Juan Pérez',
                ],
            ],
        ];
    }

    private function matches(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains($text, $this->normalize($phrase))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        return Str::of($text)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }
}
