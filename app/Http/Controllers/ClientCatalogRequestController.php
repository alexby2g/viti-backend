<?php

namespace App\Http\Controllers;

use App\Models\{CatalogoAplicacion,Cuestionario,SolicitudRespuesta,SolicitudSistema};
use App\Services\TenantContext;
use App\Support\{Audit,Code};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientCatalogRequestController extends Controller
{
    public function store(Request $request, CatalogoAplicacion $catalogoAplicacion, TenantContext $tenants): JsonResponse
    {
        abort_unless($catalogoAplicacion->activo && $catalogoAplicacion->solicitable,422,'Esta aplicación no está disponible para solicitud.');
        $empresa = $tenants->resolve($request);
        $tenants->assertCanManage($request->user(),$empresa);
        $cliente = $empresa->cliente;
        abort_unless($cliente,422,'Este negocio todavía no tiene un cliente titular asociado para crear solicitudes comerciales.');

        $existing = SolicitudSistema::query()
            ->where('cliente_id',$cliente->id)->where('empresa_id',$empresa->id)
            ->whereNotIn('estado',['cerrada','rechazada'])->latest()->first();
        if ($existing) {
            return response()->json(['data'=>[
                'solicitud'=>$existing,
                'enlace_publico'=>'/solicitar/'.$existing->public_token,
            ]]);
        }

        $questionnaire = Cuestionario::where('activo',true)->latest('id')->first();
        abort_unless($questionnaire,422,'No hay un cuestionario activo disponible.');

        $solicitud = DB::transaction(function () use ($cliente,$empresa,$catalogoAplicacion,$questionnaire): SolicitudSistema {
            $item = SolicitudSistema::create([
                'empresa_id'=>$empresa->id,
                'cliente_id'=>$cliente->id,
                'cuestionario_id'=>$questionnaire->id,
                'codigo'=>Code::next('solicitudes_sistema','SOL'),
                'public_token'=>Str::random(48),
                'publico_habilitado'=>true,
                'titulo'=>'Solicitud · '.$catalogoAplicacion->nombre,
                'resumen'=>'Aplicación solicitada desde el catálogo VITI para '.$empresa->nombre_comercial.'.',
                'estado'=>'borrador',
                'prioridad'=>'normal',
            ]);

            $map = [1=>$empresa->nombre_comercial,2=>$cliente->nombre,3=>$cliente->telefono,4=>$empresa->actividad];
            foreach ($questionnaire->secciones()->with('preguntas')->get()->flatMap->preguntas as $pregunta) {
                if (array_key_exists($pregunta->numero,$map) && filled($map[$pregunta->numero])) {
                    SolicitudRespuesta::updateOrCreate(
                        ['solicitud_id'=>$item->id,'pregunta_id'=>$pregunta->id],
                        ['respuesta_texto'=>(string)$map[$pregunta->numero]]
                    );
                }
            }
            return $item;
        });

        Audit::log($request,'app_catalogo_solicitada',$solicitud,'El negocio solicitó '.$catalogoAplicacion->nombre.' desde el catálogo VITI.',['catalogo_id'=>$catalogoAplicacion->id]);
        return response()->json(['data'=>[
            'solicitud'=>$solicitud,
            'enlace_publico'=>'/solicitar/'.$solicitud->public_token,
        ]],201);
    }
}
