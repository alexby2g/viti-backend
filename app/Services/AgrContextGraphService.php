<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\SolicitudSistema;

class AgrContextGraphService
{
    public function companyContext(string $query): array
    {
        $company = Empresa::query()
            ->with([
                'cliente:id,nombre,apellido,telefono,email',
                'solicitudes:id,empresa_id,cliente_id,codigo,titulo,estado,prioridad,fecha_limite_deseada',
                'solicitudes.proyecto:id,solicitud_id,codigo,nombre,estado,progreso',
                'proyectos:id,empresa_id,cliente_id,solicitud_id,codigo,nombre,estado,progreso',
                'proyectos.aplicacion:id,proyecto_id,nombre,estado',
            ])
            ->where('nombre_comercial', 'like', '%'.$query.'%')
            ->orWhere('razon_social', 'like', '%'.$query.'%')
            ->first();

        if (!$company) {
            return [
                'found' => false,
                'query' => $query,
                'message' => 'No encontré una empresa que coincida con la búsqueda.',
            ];
        }

        $requests = $company->solicitudes->map(function ($request) {
            return [
                'id' => $request->id,
                'codigo' => $request->codigo,
                'titulo' => $request->titulo,
                'estado' => $request->estado,
                'prioridad' => $request->prioridad,
                'project' => $request->proyecto ? [
                    'id' => $request->proyecto->id,
                    'codigo' => $request->proyecto->codigo,
                    'nombre' => $request->proyecto->nombre,
                    'estado' => $request->proyecto->estado,
                    'progreso' => $request->proyecto->progreso,
                ] : null,
            ];
        })->values()->all();

        $projects = $company->proyectos->map(fn ($project) => [
            'id' => $project->id,
            'codigo' => $project->codigo,
            'nombre' => $project->nombre,
            'estado' => $project->estado,
            'progreso' => $project->progreso,
            'application' => $project->aplicacion ? [
                'id' => $project->aplicacion->id,
                'nombre' => $project->aplicacion->nombre,
                'estado' => $project->aplicacion->estado,
            ] : null,
        ])->values()->all();

        $approvedWithoutProject = collect($requests)->where('estado', 'aprobada')->filter(fn ($item) => $item['project'] === null)->count();
        $openRequests = collect($requests)->whereNotIn('estado', ['completada', 'rechazada', 'cancelada'])->count();
        $openProjects = collect($projects)->where('estado', 'activo')->count();

        return [
            'found' => true,
            'entity' => 'empresa',
            'company' => [
                'id' => $company->id,
                'codigo' => $company->codigo,
                'nombre_comercial' => $company->nombre_comercial,
                'razon_social' => $company->razon_social,
                'estado' => $company->estado,
                'cliente' => $company->cliente ? [
                    'id' => $company->cliente->id,
                    'nombre' => trim($company->cliente->nombre.' '.$company->cliente->apellido),
                    'telefono' => $company->cliente->telefono,
                    'email' => $company->cliente->email,
                ] : null,
            ],
            'graph' => [
                'requests' => $requests,
                'projects' => $projects,
            ],
            'signals' => [
                'open_requests' => $openRequests,
                'open_projects' => $openProjects,
                'approved_requests_without_project' => $approvedWithoutProject,
            ],
            'next_attention' => $approvedWithoutProject > 0
                ? 'Preparar la conversión de la solicitud aprobada a proyecto.'
                : ($openRequests > 0 ? 'Revisar las solicitudes que continúan abiertas.' : null),
        ];
    }

    public function requestContext(SolicitudSistema $request): array
    {
        $request->load([
            'empresa.cliente',
            'cliente',
            'proyecto.aplicacion',
        ]);

        return [
            'found' => true,
            'entity' => 'solicitud',
            'request' => [
                'id' => $request->id,
                'codigo' => $request->codigo,
                'titulo' => $request->titulo,
                'estado' => $request->estado,
                'prioridad' => $request->prioridad,
            ],
            'company' => $request->empresa ? [
                'id' => $request->empresa->id,
                'nombre_comercial' => $request->empresa->nombre_comercial,
            ] : null,
            'client' => $request->cliente ? [
                'id' => $request->cliente->id,
                'nombre' => trim($request->cliente->nombre.' '.$request->cliente->apellido),
            ] : null,
            'project' => $request->proyecto ? [
                'id' => $request->proyecto->id,
                'codigo' => $request->proyecto->codigo,
                'nombre' => $request->proyecto->nombre,
                'estado' => $request->proyecto->estado,
                'progreso' => $request->proyecto->progreso,
                'application' => $request->proyecto->aplicacion ? [
                    'id' => $request->proyecto->aplicacion->id,
                    'nombre' => $request->proyecto->aplicacion->nombre,
                    'estado' => $request->proyecto->aplicacion->estado,
                ] : null,
            ] : null,
        ];
    }
}
