<?php

namespace App\Http\Controllers;

use App\Models\SolicitudRespuesta;
use App\Models\SolicitudSistema;
use App\Models\Conversacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicSolicitudController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $solicitud = $this->resolve($token);
        return response()->json(['data'=>$solicitud->load([
            'empresa:id,nombre_comercial,actividad,telefono,whatsapp,logo_path',
            'cliente:id,nombre,telefono,whatsapp',
            'cuestionario.secciones.preguntas',
            'respuestas.pregunta',
        ])]);
    }

    public function save(Request $request, string $token): JsonResponse
    {
        $solicitud = $this->resolve($token);
        abort_if(in_array($solicitud->estado, ['aprobada','convertida','cerrada'], true), 422, 'Esta solicitud ya no admite cambios.');
        $data = $request->validate([
            'respuestas'=>['required','array'],
            'respuestas.*.pregunta_id'=>['required','integer','exists:cuestionario_preguntas,id'],
            'respuestas.*.valor'=>['nullable'],
            'declaracion_aceptada'=>['nullable','boolean'],
            'declaracion_nombre'=>['nullable','string','max:180'],
            'declaracion_fecha'=>['nullable','date'],
        ]);

        DB::transaction(function () use ($data, $solicitud): void {
            foreach ($data['respuestas'] as $item) {
                $value = $item['valor'] ?? null;
                SolicitudRespuesta::updateOrCreate(
                    ['solicitud_id'=>$solicitud->id,'pregunta_id'=>$item['pregunta_id']],
                    is_array($value)
                        ? ['respuesta_json'=>$value,'respuesta_texto'=>null]
                        : ['respuesta_texto'=>$value===null?null:(string)$value,'respuesta_json'=>null]
                );
            }
            $solicitud->update([
                'declaracion_aceptada'=>(bool)($data['declaracion_aceptada']??false),
                'declaracion_nombre'=>$data['declaracion_nombre']??null,
                'declaracion_fecha'=>$data['declaracion_fecha']??null,
            ]);
        });

        ClientPortalController::syncCompany($solicitud->fresh(), $solicitud->cliente);
        $solicitud->cliente?->update(['estado'=>'formulario_en_proceso']);
        return response()->json(['message'=>'Tus respuestas fueron guardadas.']);
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $solicitud = $this->resolve($token);
        $requiredIds = $solicitud->cuestionario->secciones()->with('preguntas')->get()->flatMap(fn($s)=>$s->preguntas)->where('obligatoria',true)->pluck('id');
        $answered = $solicitud->respuestas()->whereIn('pregunta_id',$requiredIds)->get()->filter(fn($a)=>filled($a->respuesta_texto)||!empty($a->respuesta_json))->pluck('pregunta_id');
        abort_if($requiredIds->diff($answered)->isNotEmpty(),422,'Completa las preguntas obligatorias.');
        abort_unless($solicitud->declaracion_aceptada && filled($solicitud->declaracion_nombre) && $solicitud->declaracion_fecha,422,'Debes aceptar la declaración final.');
        ClientPortalController::syncCompany($solicitud->fresh(), $solicitud->cliente);
        $solicitud->update(['estado'=>'en_revision','enviado_at'=>now()]);
        $solicitud->cliente?->update(['estado'=>'informacion_recibida']);
        Conversacion::firstOrCreate(
            ['cliente_id'=>$solicitud->cliente_id,'solicitud_id'=>$solicitud->id],
            ['asunto'=>'Revisión de '.$solicitud->codigo,'estado'=>'abierta','ultimo_mensaje_at'=>now()]
        );
        return response()->json(['message'=>'Solicitud enviada. El equipo de VITI revisará la información y podrá responderte desde tu buzón.']);
    }

    private function resolve(string $token): SolicitudSistema
    {
        return SolicitudSistema::query()->where('public_token',$token)->where('publico_habilitado',true)->firstOrFail();
    }
}
