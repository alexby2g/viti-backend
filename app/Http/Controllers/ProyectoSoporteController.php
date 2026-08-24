<?php
namespace App\Http\Controllers;

use App\Models\{Aplicacion,Mantenimiento,Proyecto};
use App\Support\{Audit,Code};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProyectoSoporteController extends Controller
{
    public function index(Request $request, Proyecto $proyecto): JsonResponse
    {
        $items=Mantenimiento::with(['aplicacion:id,nombre','asignado:id,nombre,apellido','creador:id,nombre,apellido'])
            ->where('proyecto_id',$proyecto->id)
            ->latest()
            ->paginate(min(max((int)$request->input('per_page',20),1),100));

        return response()->json($items);
    }

    public function store(Request $request, Proyecto $proyecto): JsonResponse
    {
        $data=$request->validate([
            'aplicacion_id'=>['required','integer','exists:aplicaciones,id'],
            'asignado_a'=>['nullable','integer','exists:usuarios,id'],
            'titulo'=>['required','string','max:200'],
            'descripcion'=>['required','string','max:5000'],
            'tipo'=>['nullable',Rule::in(['soporte','error','mejora','actualizacion','capacitacion'])],
            'prioridad'=>['nullable',Rule::in(['baja','normal','alta','urgente'])],
        ]);

        $app=Aplicacion::query()->findOrFail((int)$data['aplicacion_id']);
        abort_unless((int)$app->empresa_id === (int)$proyecto->empresa_id,422,'La aplicación no corresponde al proyecto.');
        if($app->proyecto_id !== null){
            abort_unless((int)$app->proyecto_id === (int)$proyecto->id,422,'La aplicación no está vinculada al proyecto seleccionado.');
        }

        $m=Mantenimiento::create($data+[
            'proyecto_id'=>$proyecto->id,
            'empresa_id'=>$proyecto->empresa_id,
            'cliente_id'=>$proyecto->cliente_id,
            'creado_por'=>$request->user()?->id,
            'tipo'=>$data['tipo']??'soporte',
            'prioridad'=>$data['prioridad']??'normal',
            'estado'=>'abierto',
            'codigo'=>Code::next('mantenimientos','MNT'),
        ]);

        Audit::log($request,'soporte_proyecto_creado',$m,'Se registró un caso de soporte vinculado al proyecto.');

        return response()->json(['data'=>$m->load(['proyecto','aplicacion','asignado','creador'])],201);
    }

    public function update(Request $request, Proyecto $proyecto, Mantenimiento $mantenimiento): JsonResponse
    {
        abort_unless((int)$mantenimiento->proyecto_id === (int)$proyecto->id,404,'El caso de soporte no pertenece a este proyecto.');

        $data=$request->validate([
            'asignado_a'=>['nullable','integer','exists:usuarios,id'],
            'titulo'=>['sometimes','string','max:200'],
            'descripcion'=>['sometimes','string','max:5000'],
            'tipo'=>['sometimes',Rule::in(['soporte','error','mejora','actualizacion','capacitacion'])],
            'prioridad'=>['sometimes',Rule::in(['baja','normal','alta','urgente'])],
            'estado'=>['sometimes',Rule::in(['abierto','en_proceso','en_espera','resuelto','cerrado'])],
        ]);

        $mantenimiento->update($data);
        Audit::log($request,'soporte_proyecto_actualizado',$mantenimiento,'Se actualizó un caso de soporte del proyecto.');
        return response()->json(['data'=>$mantenimiento->fresh()->load(['proyecto','aplicacion','asignado','creador'])]);
    }
}
