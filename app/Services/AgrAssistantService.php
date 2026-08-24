<?php

namespace App\Services;

use App\Models\{Aplicacion, Cliente, Empresa, Mantenimiento, Proyecto, SolicitudSistema, Suscripcion};
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

        if ($this->matches($text, ['resumen', 'resumen de viti', 'estado de viti', 'como esta viti', 'como va viti'])) {
            return $this->summaryResponse();
        }

        if ($this->matches($text, ['abrir clientes', 'ir a clientes', 'mostrar clientes'])) {
            return $this->navigationResponse('clients', 'Claro. Abriendo clientes.', '/empresas');
        }

        if ($this->matches($text, ['abrir empresas', 'ir a empresas', 'mostrar empresas'])) {
            return $this->navigationResponse('companies', 'Claro. Abriendo empresas.', '/empresas');
        }

        if ($this->matches($text, ['abrir solicitudes', 'ir a solicitudes', 'mostrar solicitudes'])) {
            return $this->navigationResponse('requests', 'Claro. Abriendo solicitudes.', '/solicitudes');
        }

        if ($this->matches($text, ['abrir proyectos', 'ir a proyectos', 'mostrar proyectos'])) {
            return $this->navigationResponse('projects', 'Claro. Abriendo proyectos.', '/proyectos');
        }

        if ($this->matches($text, ['abrir pagos', 'ir a pagos', 'mostrar pagos'])) {
            return $this->navigationResponse('payments', 'Claro. Abriendo pagos.', '/pagos');
        }

        if ($this->matches($text, ['abrir soportes', 'ir a soportes', 'mostrar soportes', 'abrir mantenimiento'])) {
            return $this->navigationResponse('support', 'Claro. Abriendo soporte y mantenimientos.', '/mantenimientos');
        }

        if ($this->matches($text, ['cuantos clientes', 'cantidad de clientes', 'total de clientes', 'numero de clientes'])) {
            $count = Cliente::query()->count();
            return $this->countResponse('count_clients', 'Actualmente hay '.$count.' clientes registrados en VITI.', $count);
        }

        if ($this->matches($text, ['cuantas empresas', 'cantidad de empresas', 'total de empresas', 'numero de empresas'])) {
            $count = Empresa::query()->count();
            return $this->countResponse('count_companies', 'Actualmente hay '.$count.' empresas registradas en VITI.', $count);
        }

        if ($this->matches($text, ['solicitudes pendientes', 'solicitudes sin resolver', 'cuantas solicitudes pendientes'])) {
            $count = SolicitudSistema::query()
                ->whereNotIn('estado', ['completada', 'rechazada', 'cancelada'])
                ->count();
            return $this->countResponse('pending_requests', 'Hay '.$count.' solicitudes pendientes.', $count);
        }

        if ($this->matches($text, ['cuantos proyectos', 'cantidad de proyectos', 'total de proyectos'])) {
            $count = Proyecto::query()->count();
            return $this->countResponse('count_projects', 'VITI tiene '.$count.' proyectos registrados.', $count);
        }

        if ($this->matches($text, ['proyectos activos', 'proyectos en curso', 'cuantos proyectos activos'])) {
            $count = Proyecto::query()->where('estado', 'activo')->count();
            return $this->countResponse('active_projects', 'Hay '.$count.' proyectos activos.', $count);
        }

        if ($this->matches($text, ['aplicaciones activas', 'apps activas', 'cuantas aplicaciones activas', 'cuantas apps activas'])) {
            $count = Aplicacion::query()->count();
            return $this->countResponse('active_apps', 'Hay '.$count.' aplicaciones registradas en VITI.', $count);
        }

        if ($this->matches($text, ['pagos vencidos', 'suscripciones vencidas', 'cuantos pagos vencidos', 'pagos con atencion'])) {
            $count = Suscripcion::query()->whereIn('estado', ['gracia', 'suspendida'])->count();
            return $this->countResponse('overdue_payments', 'Hay '.$count.' suscripciones que requieren atención por pago.', $count);
        }

        if ($this->matches($text, ['soportes abiertos', 'mantenimientos abiertos', 'cuantos soportes abiertos'])) {
            $count = Mantenimiento::query()->whereNotIn('estado', ['resuelto', 'cerrado'])->count();
            return $this->countResponse('open_support', 'Hay '.$count.' mantenimientos o soportes abiertos.', $count);
        }

        if (preg_match('/^(buscar|busca|encuentra|mostrar|muestrame|muéstrame)\s+(al\s+)?cliente\s+(.+)$/u', $text, $matches)) {
            return $this->searchClient($matches[3]);
        }

        if (preg_match('/^(buscar|busca|encuentra|mostrar|muestrame|muéstrame)\s+(la\s+|la\s+empresa\s+|empresa\s+)(.+)$/u', $text, $matches)) {
            return $this->searchCompany($matches[4]);
        }

        if (str_contains($text, 'cliente') && preg_match('/cliente\s+(.+)/u', $text, $matches)) {
            return $this->searchClient($matches[1]);
        }

        if (str_contains($text, 'empresa') && preg_match('/empresa\s+(.+)/u', $text, $matches)) {
            return $this->searchCompany($matches[1]);
        }

        return [
            'intent' => 'unknown',
            'message' => 'Todavía no conozco ese comando. Prueba con "resumen", "abrir clientes", "cuántos clientes tengo", "proyectos activos", "pagos vencidos" o "buscar cliente Juan".',
            'data' => [],
        ];
    }

    private function summaryResponse(): array
    {
        $clients = Cliente::query()->count();
        $companies = Empresa::query()->where('estado', 'activo')->count();
        $requests = SolicitudSistema::query()->whereNotIn('estado', ['completada', 'rechazada', 'cancelada'])->count();
        $projects = Proyecto::query()->where('estado', 'activo')->count();
        $payments = Suscripcion::query()->whereIn('estado', ['gracia', 'suspendida'])->count();
        $support = Mantenimiento::query()->whereNotIn('estado', ['resuelto', 'cerrado'])->count();

        return [
            'intent' => 'summary',
            'message' => 'Resumen actual de VITI: '.$clients.' clientes, '.$companies.' empresas activas, '.$requests.' solicitudes pendientes, '.$projects.' proyectos activos, '.$payments.' pagos con atención y '.$support.' soportes abiertos.',
            'data' => compact('clients', 'companies', 'requests', 'projects', 'payments', 'support'),
        ];
    }

    private function countResponse(string $intent, string $message, int $count): array
    {
        return [
            'intent' => $intent,
            'message' => $message,
            'data' => ['count' => $count],
        ];
    }

    private function navigationResponse(string $intent, string $message, string $to): array
    {
        return [
            'intent' => 'navigate_'.$intent,
            'message' => $message,
            'data' => [
                'action' => [
                    'type' => 'navigate',
                    'to' => $to,
                    'label' => 'Abrir',
                ],
            ],
        ];
    }

    private function searchClient(string $name): array
    {
        $name = $this->cleanSearchTerm($name);

        if ($name === '') {
            return [
                'intent' => 'search_client',
                'message' => 'Necesito el nombre, teléfono o correo del cliente que quieres buscar.',
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

    private function searchCompany(string $name): array
    {
        $name = $this->cleanSearchTerm($name);

        if ($name === '') {
            return [
                'intent' => 'search_company',
                'message' => 'Necesito el nombre de la empresa que quieres buscar.',
                'data' => ['results' => []],
            ];
        }

        $companies = Empresa::query()
            ->where(function ($query) use ($name) {
                $query->where('nombre_comercial', 'like', '%'.$name.'%')
                    ->orWhere('razon_social', 'like', '%'.$name.'%')
                    ->orWhere('codigo', 'like', '%'.$name.'%');
            })
            ->limit(8)
            ->get(['id', 'codigo', 'nombre_comercial', 'razon_social', 'estado', 'telefono', 'ciudad']);

        if ($companies->isEmpty()) {
            return [
                'intent' => 'search_company',
                'message' => 'No encontré empresas que coincidan con "'.$name.'".',
                'data' => ['results' => []],
            ];
        }

        return [
            'intent' => 'search_company',
            'message' => 'Encontré '.$companies->count().' empresa(s) para "'.$name.'".',
            'data' => ['results' => $companies->values()->all()],
        ];
    }

    private function helpResponse(): array
    {
        return [
            'intent' => 'help',
            'message' => 'Soy AGR Assistant. Trabajo con reglas locales y datos reales de VITI, sin API externa.',
            'data' => [
                'commands' => [
                    'Resumen',
                    'Abrir clientes',
                    'Abrir solicitudes',
                    'Abrir proyectos',
                    'Cuántos clientes tengo',
                    'Cuántas empresas tengo',
                    'Solicitudes pendientes',
                    'Proyectos activos',
                    'Pagos vencidos',
                    'Soportes abiertos',
                    'Buscar cliente Juan Pérez',
                    'Buscar empresa Sahory',
                ],
            ],
        ];
    }

    private function cleanSearchTerm(string $term): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $term));
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
