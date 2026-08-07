<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,Archivo,Cliente,Empresa,Proyecto,ProyectoAvance,SolicitudSistema};
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArchivoController extends Controller
{
    private const TYPES = [
        'cliente' => Cliente::class,
        'empresa' => Empresa::class,
        'solicitud' => SolicitudSistema::class,
        'proyecto' => Proyecto::class,
        'aplicacion' => Aplicacion::class,
        'avance' => ProyectoAvance::class,
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Archivo::with('usuario:id,nombre,apellido')->latest();
        if ($request->filled('tipo') && $request->filled('id')) {
            $model = $this->resolve($request->string('tipo')->toString(), (int) $request->input('id'));
            $query->where('adjuntable_type', $model::class)->where('adjuntable_id', $model->getKey());
        }
        return response()->json($query->paginate(30));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo' => ['required','string','in:'.implode(',', array_keys(self::TYPES))],
            'id' => ['required','integer'],
            'archivo' => ['required','file','max:10240','mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,zip'],
            'categoria' => ['nullable','string','max:50'],
            'descripcion' => ['nullable','string','max:1000'],
        ]);
        $model = $this->resolve($data['tipo'], (int) $data['id']);
        $file = $request->file('archivo');
        $path = $file->store('archivos/'.now()->format('Y/m'), 'private_uploads');
        $archivo = Archivo::create([
            'adjuntable_type' => $model::class,
            'adjuntable_id' => $model->getKey(),
            'subido_por' => $request->user()->id,
            'categoria' => $data['categoria'] ?? 'general',
            'nombre_original' => $file->getClientOriginalName(),
            'ruta' => $path,
            'mime' => $file->getMimeType(),
            'tamano' => $file->getSize(),
            'descripcion' => $data['descripcion'] ?? null,
        ]);
        Audit::log($request, 'archivo_subido', $archivo, 'Se subió un archivo.');
        return response()->json(['data'=>$archivo], 201);
    }

    public function download(Archivo $archivo): StreamedResponse
    {
        return $this->stream($archivo);
    }

    public function clientDownload(Request $request, Archivo $archivo): StreamedResponse
    {
        $clienteId = (int) $request->user()->cliente_id;
        $allowed = false;
        if ($archivo->adjuntable_type === ProyectoAvance::class) {
            $allowed = ProyectoAvance::whereKey($archivo->adjuntable_id)
                ->whereHas('proyecto', fn ($q) => $q->where('cliente_id', $clienteId))
                ->where('visible_cliente', true)->exists();
        } elseif ($archivo->adjuntable_type === Proyecto::class) {
            $allowed = Proyecto::whereKey($archivo->adjuntable_id)->where('cliente_id',$clienteId)->exists();
        } elseif ($archivo->adjuntable_type === SolicitudSistema::class) {
            $allowed = SolicitudSistema::whereKey($archivo->adjuntable_id)->where('cliente_id',$clienteId)->exists();
        } elseif ($archivo->adjuntable_type === Cliente::class) {
            $allowed = (int)$archivo->adjuntable_id === $clienteId;
        }
        abort_unless($allowed, 403, 'No tienes permiso para descargar este archivo.');
        return $this->stream($archivo);
    }

    public function destroy(Request $request, Archivo $archivo): JsonResponse
    {
        foreach (['private_uploads','public'] as $disk) {
            if (Storage::disk($disk)->exists($archivo->ruta)) {
                Storage::disk($disk)->delete($archivo->ruta);
            }
        }
        $archivo->delete();
        Audit::log($request, 'archivo_eliminado', $archivo, 'Se eliminó un archivo.');
        return response()->json(status:204);
    }

    private function stream(Archivo $archivo): StreamedResponse
    {
        // Compatibilidad con archivos antiguos de V16 que estaban en el disco public.
        $diskName = Storage::disk('private_uploads')->exists($archivo->ruta) ? 'private_uploads' : 'public';
        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($archivo->ruta), 404, 'El archivo ya no está disponible.');

        $stream = $disk->readStream($archivo->ruta);
        abort_unless(is_resource($stream), 404, 'No pudimos abrir el archivo solicitado.');

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, $archivo->nombre_original, [
            'Content-Type' => $archivo->mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function resolve(string $type, int $id): Model
    {
        $class = self::TYPES[$type] ?? abort(422, 'Tipo de archivo no permitido.');
        return $class::query()->findOrFail($id);
    }
}
