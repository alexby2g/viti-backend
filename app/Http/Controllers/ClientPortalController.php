<?php

namespace App\Http\Controllers;

use App\Models\{Cliente,Cuestionario,Empresa,SolicitudRespuesta,SolicitudSistema};
use App\Support\{Audit,Code};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ClientPortalController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        $cliente = $this->client($request)->load(['usuario:id,cliente_id,usuario','empresas','solicitudes'=>fn($q)=>$q->latest()]);
        return response()->json(['data'=>$cliente]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $cliente = $this->client($request);
        $request->merge(['ci' => preg_replace('/\D+/', '', (string) $request->input('ci'))]);
        $data = $request->validate([
            'nombre' => ['required','string','max:180'],
            'whatsapp' => ['nullable','regex:/^[0-9]{7,15}$/'],
            'ci' => ['required','regex:/^[0-9]{5,15}$/','unique:clientes,documento,'.$cliente->id,Rule::unique('usuarios','documento')->ignore($request->user()->id)],
            'ci_expedido' => ['nullable','string','max:20'],
            'ciudad' => ['required','string','max:100'],
            'direccion' => ['nullable','string','max:255'],
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
            'whatsapp.regex' => 'El WhatsApp debe contener entre 7 y 15 dígitos.',
            'ci.required' => 'La cédula de identidad es obligatoria.',
            'ci.regex' => 'El CI debe contener entre 5 y 15 dígitos.',
            'ci.unique' => 'Ese número de cédula ya está registrado.',
            'ciudad.required' => 'La ciudad o localidad es obligatoria.',
        ]);
        $cliente->update([
            'nombre'=>$data['nombre'], 'whatsapp'=>$data['whatsapp']??null,
            'documento'=>$data['ci'], 'ci_expedido'=>$data['ci_expedido']??null, 'ciudad'=>$data['ciudad'], 'direccion'=>$data['direccion']??null,
        ]);
        $request->user()->update(['nombre'=>$data['nombre'],'documento'=>$data['ci']]);
        Audit::log($request,'cliente_perfil_actualizado',$cliente,'El cliente actualizó su perfil.');
        return response()->json(['data'=>$cliente->fresh()]);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $cliente = $this->client($request);
        $request->validate(['foto'=>['required','image','mimes:jpg,jpeg,png,webp','max:3072']], [
            'foto.required'=>'Selecciona una fotografía.',
            'foto.image'=>'El archivo debe ser una imagen válida.',
            'foto.mimes'=>'La fotografía debe ser JPG, PNG o WEBP.',
            'foto.max'=>'La fotografía no puede superar 3 MB.'
        ]);

        $disk = Storage::disk('public');
        $oldPath = $cliente->foto_path;

        try {
            $path = $request->file('foto')->store('clientes/fotos','public');
            if (!$path || !$disk->exists($path)) {
                throw new \RuntimeException('R2 no confirmó el archivo después de escribirlo.');
            }

            $cliente->update(['foto_path'=>$path,'foto_verificada'=>false]);

            if ($oldPath && $oldPath !== $path) {
                try { $disk->delete($oldPath); } catch (Throwable) {}
            }

            $fresh = $cliente->fresh();
            return response()->json([
                'message'=>'Fotografía actualizada correctamente.',
                'data'=>$fresh,
                'foto_url'=>$fresh->foto_url,
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'message'=>'No pudimos guardar la fotografía en el almacenamiento permanente. Revisa la conexión de R2 en Administración → Almacenamiento.',
            ], 503);
        }
    }

    public function currentRequest(Request $request): JsonResponse
    {
        $cliente = $this->client($request);
        $solicitud = SolicitudSistema::query()->where('cliente_id',$cliente->id)->latest()->first();
        return response()->json(['data'=>$solicitud?->load(['empresa','planViti','cuestionario.secciones.preguntas','respuestas'])]);
    }

    public function startRequest(Request $request): JsonResponse
    {
        $cliente = $this->client($request);
        $existing = SolicitudSistema::query()->where('cliente_id',$cliente->id)->whereNotIn('estado',['cerrada','rechazada'])->latest()->first();
        if ($existing) return response()->json(['data'=>$existing->load(['empresa','planViti','cuestionario.secciones.preguntas','respuestas'])]);

        $cuestionario = Cuestionario::query()->where('activo',true)->latest('id')->first();
        abort_unless($cuestionario, 422, 'No hay un cuestionario activo disponible.');

        $solicitud = DB::transaction(function () use ($cliente,$cuestionario): SolicitudSistema {
            $solicitud = SolicitudSistema::create([
                'empresa_id'=>null,
                'cliente_id'=>$cliente->id,
                'cuestionario_id'=>$cuestionario->id,
                'codigo'=>Code::next('solicitudes_sistema','SOL'),
                'public_token'=>Str::random(48),
                'publico_habilitado'=>true,
                'titulo'=>'Nueva solicitud de sistema',
                'estado'=>'borrador',
                'prioridad'=>'normal',
                'acuerdo_comercial_requerido'=>true,
            ]);

            $map = [2=>$cliente->nombre, 3=>$cliente->telefono];
            foreach ($cuestionario->secciones()->with('preguntas')->get()->flatMap->preguntas as $pregunta) {
                if (array_key_exists($pregunta->numero,$map)) {
                    SolicitudRespuesta::updateOrCreate(
                        ['solicitud_id'=>$solicitud->id,'pregunta_id'=>$pregunta->id],
                        ['respuesta_texto'=>$map[$pregunta->numero]]
                    );
                }
            }
            return $solicitud;
        });

        Audit::log($request,'solicitud_cliente_iniciada',$solicitud,'El cliente inició su levantamiento de requerimientos.');
        return response()->json(['data'=>$solicitud->load(['planViti','cuestionario.secciones.preguntas','respuestas'])],201);
    }

    public function syncFromQuestionnaire(Request $request, SolicitudSistema $solicitud): JsonResponse
    {
        $cliente = $this->client($request);
        abort_unless((int)$solicitud->cliente_id === (int)$cliente->id, 403, 'No tienes permiso para modificar esta solicitud.');
        $this->syncCompany($solicitud, $cliente);
        return response()->json(['data'=>$solicitud->fresh()->load('empresa')]);
    }

    public static function syncCompany(SolicitudSistema $solicitud, Cliente $cliente): ?Empresa
    {
        $answers = $solicitud->respuestas()->with('pregunta')->get()->keyBy(fn($r)=>$r->pregunta?->numero);
        $name = trim((string)($answers->get(1)?->respuesta_texto ?? ''));
        $activity = trim((string)($answers->get(4)?->respuesta_texto ?? ''));
        if ($name === '' || in_array(Str::lower($name), ['no aplica','no','ninguno','ninguna'], true)) return null;

        $empresa = $solicitud->empresa ?: $cliente->empresas()->where('nombre_comercial',$name)->first();
        if (!$empresa) {
            $empresa = Empresa::create([
                'cliente_id'=>$cliente->id,
                'codigo'=>Code::next('empresas','EMP'),
                'nombre_comercial'=>$name,
                'actividad'=>$activity ?: null,
                'telefono'=>$cliente->telefono,
                'whatsapp'=>$cliente->whatsapp,
                'ciudad'=>$cliente->ciudad,
                'direccion'=>$cliente->direccion,
                'estado'=>'pendiente_revision',
            ]);
        } else {
            $empresa->update(['actividad'=>$activity ?: $empresa->actividad]);
        }
        if (!$solicitud->empresa_id) $solicitud->update(['empresa_id'=>$empresa->id]);
        if ($solicitud->titulo === 'Nueva solicitud de sistema') $solicitud->update(['titulo'=>'Sistema para '.$empresa->nombre_comercial]);
        return $empresa;
    }

    private function client(Request $request): Cliente
    {
        return Cliente::query()->findOrFail($request->user()->cliente_id);
    }
}
