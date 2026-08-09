<?php

namespace App\Services;

use App\Models\{Aplicacion,Archivo,Cliente,Empresa,Mensaje,Proyecto,ProyectoAvance,SolicitudSistema,Usuario};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AccountDeletionService
{
    public function deleteUser(Usuario $usuario): array
    {
        if ($usuario->cliente_id) {
            $cliente = Cliente::withTrashed()->find($usuario->cliente_id);
            if ($cliente) return $this->deleteClient($cliente);
        }

        $username = $usuario->usuario;
        $files = collect();
        $this->collectFiles($usuario, $files);

        DB::transaction(function () use ($usuario): void {
            $usuario->negocios()->detach();
            $usuario->delete();
        });

        $this->deleteStoredFiles($files);

        return [
            'usuario' => $username,
            'empresas_eliminadas' => 0,
            'solicitudes_eliminadas' => 0,
            'proyectos_eliminados' => 0,
            'aplicaciones_eliminadas' => 0,
        ];
    }

    public function deleteClient(Cliente $cliente): array
    {
        $files = collect();

        $summary = DB::transaction(function () use ($cliente, $files): array {
            $cliente = Cliente::withTrashed()->lockForUpdate()->findOrFail($cliente->id);
            $empresas = Empresa::withTrashed()->where('cliente_id', $cliente->id)->get();
            $solicitudes = SolicitudSistema::withTrashed()->where('cliente_id', $cliente->id)->get();
            $proyectos = Proyecto::withTrashed()->where('cliente_id', $cliente->id)->get();
            $aplicaciones = Aplicacion::withTrashed()->whereIn('empresa_id', $empresas->pluck('id'))->get();
            $avances = ProyectoAvance::whereIn('proyecto_id', $proyectos->pluck('id'))->get();
            $usuarios = Usuario::where('cliente_id', $cliente->id)->get();

            collect([$cliente])
                ->concat($empresas)
                ->concat($solicitudes)
                ->concat($proyectos)
                ->concat($aplicaciones)
                ->concat($avances)
                ->concat($usuarios)
                ->each(fn (Model $model) => $this->collectFiles($model, $files));

            Mensaje::query()
                ->whereNotNull('archivo_path')
                ->whereHas('conversacion', fn ($query) => $query->where('cliente_id', $cliente->id))
                ->pluck('archivo_path')
                ->each(fn (string $path) => $files->push(['disk' => 'private_uploads', 'path' => $path]));

            $summary = [
                'usuario' => $cliente->usuario?->usuario,
                'empresas_eliminadas' => $empresas->count(),
                'solicitudes_eliminadas' => $solicitudes->count(),
                'proyectos_eliminados' => $proyectos->count(),
                'aplicaciones_eliminadas' => $aplicaciones->count(),
            ];

            // forceDelete es intencional: aquí se elimina también la información
            // relacionada de Neon, en lugar de dejarla oculta en la papelera.
            $empresas->each->forceDelete();
            Proyecto::withTrashed()->where('cliente_id', $cliente->id)->forceDelete();
            SolicitudSistema::withTrashed()->where('cliente_id', $cliente->id)->forceDelete();
            $usuarios->each(function (Usuario $usuario): void {
                $usuario->negocios()->detach();
                $usuario->delete();
            });
            $cliente->forceDelete();

            return $summary;
        });

        $this->deleteStoredFiles($files);

        return $summary;
    }

    private function deleteStoredFiles($files): void
    {
        $files->filter(fn (array $item) => filled($item['path'] ?? null))
            ->unique(fn (array $item) => $item['disk'].'|'.$item['path'])
            ->each(function (array $item): void {
                try { Storage::disk($item['disk'])->delete($item['path']); }
                catch (Throwable $e) { report($e); }
            });
    }

    private function collectFiles(Model $model, $files): void
    {
        if (method_exists($model, 'archivos')) {
            $model->archivos()->get()->each(function (Archivo $archivo) use ($files): void {
                $files->push(['disk' => 'private_uploads', 'path' => $archivo->ruta]);
                $files->push(['disk' => 'public', 'path' => $archivo->ruta]);
                $archivo->delete();
            });
        }

        foreach (['foto_path', 'logo_path'] as $column) {
            if (filled($model->getAttribute($column))) {
                $files->push(['disk' => 'public', 'path' => $model->getAttribute($column)]);
            }
        }
    }
}
